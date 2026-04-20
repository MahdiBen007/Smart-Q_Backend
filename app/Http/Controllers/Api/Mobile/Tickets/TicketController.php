<?php

namespace App\Http\Controllers\Api\Mobile\Tickets;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Models\Appointment;
use App\Models\QrCodeToken;
use App\Models\QueueEntry;
use App\Models\User;
use App\Support\Dashboard\BookingCodeFormatter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class TicketController extends MobileApiController
{
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $customer = $user->customer;
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        if (! $customer) {
            return $this->respond([], meta: $this->paginationMeta($page, $perPage, 0));
        }

        $allTickets = Cache::remember(
            sprintf('mobile:tickets:%s', $user->getKey()),
            now()->addSeconds(8),
            function () use ($customer): array {
                $appointments = Appointment::query()
                    ->with([
                        'branch:id,branch_name',
                        'service:id,service_name',
                        'queueEntries:id,appointment_id,queue_status,updated_at',
                    ])
                    ->select([
                        'id',
                        'customer_id',
                        'branch_id',
                        'service_id',
                        'appointment_date',
                        'appointment_time',
                        'appointment_status',
                    ])
                    ->where('customer_id', $customer->getKey())
                    ->latest('appointment_date')
                    ->get();

                $appointmentIds = $appointments->pluck('id')->filter()->values()->all();
                $appointmentTokens = $this->latestAppointmentTokens($appointmentIds);

                $appointmentTickets = $appointments->map(function (Appointment $appointment) use ($appointmentTokens) {
                    $token = $appointmentTokens[$appointment->getKey()] ?? null;

                    $dateLine = $appointment->appointment_date
                        ? Carbon::parse($appointment->appointment_date)->format('M d, Y')
                        : '--';
                    $timeLine = $appointment->appointment_time
                        ? Carbon::parse($appointment->appointment_time)->format('g:i A')
                        : '--';

                    $status = $this->ticketStatus($appointment);

                    return [
                        'id' => $appointment->getKey(),
                        'kind' => 'appointment',
                        'booking_code' => BookingCodeFormatter::appointmentDisplayCode($appointment),
                        'title' => $appointment->service?->service_name ?? 'Service',
                        'subtitle' => $appointment->branch?->branch_name ?? 'Branch',
                        'date_line_1' => $dateLine,
                        'date_line_2' => $timeLine,
                        'status' => $status,
                        'status_pill' => ucfirst($status),
                        'access_key' => $token?->token_value ?? '',
                        'can_cancel' => $status === 'upcoming',
                    ];
                });

                $queueEntries = QueueEntry::query()
                    ->with([
                        'walkInTicket.branch:id,branch_name',
                        'walkInTicket.service:id,service_name',
                        'queueSession:id,session_date',
                    ])
                    ->select([
                        'id',
                        'customer_id',
                        'ticket_id',
                        'queue_session_id',
                        'queue_status',
                        'checked_in_at',
                        'created_at',
                        'updated_at',
                    ])
                    ->where('customer_id', $customer->getKey())
                    ->whereNotNull('ticket_id')
                    ->latest('updated_at')
                    ->get();

                $walkInTicketIds = $queueEntries
                    ->pluck('ticket_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                $walkInTokens = $this->latestWalkInTokens($walkInTicketIds);

                $walkInTickets = $queueEntries->map(function (QueueEntry $entry) use ($walkInTokens) {
                    $ticket = $entry->walkInTicket;

                    if (! $ticket) {
                        return null;
                    }

                    $token = $walkInTokens[$ticket->getKey()] ?? null;

                    $sessionDate = $entry->queueSession?->session_date
                        ? Carbon::parse($entry->queueSession->session_date)->format('M d, Y')
                        : Carbon::parse($entry->created_at)->format('M d, Y');
                    $timeLine = $entry->checked_in_at
                        ? Carbon::parse($entry->checked_in_at)->format('g:i A')
                        : '--';

                    $status = $this->queueTicketStatus($entry, $sessionDate);

                    return [
                        'id' => $ticket->getKey(),
                        'kind' => 'walk_in',
                        'booking_code' => BookingCodeFormatter::walkInDisplayCode($ticket),
                        'title' => $ticket->service?->service_name ?? 'Walk-in Service',
                        'subtitle' => $ticket->branch?->branch_name ?? 'Branch',
                        'date_line_1' => $sessionDate,
                        'date_line_2' => $timeLine,
                        'status' => $status,
                        'status_pill' => ucfirst($status),
                        'access_key' => $token?->token_value ?? '',
                        'can_cancel' => false,
                    ];
                })->filter()->values();

                return $appointmentTickets
                    ->concat($walkInTickets)
                    ->sortByDesc('date_line_1')
                    ->values()
                    ->all();
            }
        );

        $total = count($allTickets);
        $offset = ($page - 1) * $perPage;
        $tickets = array_slice($allTickets, $offset, $perPage);

        return $this->respond(
            $tickets,
            meta: $this->paginationMeta($page, $perPage, $total),
        );
    }

    public function show(Request $request, Appointment $appointment)
    {
        /** @var User $user */
        $user = $request->user();
        $customer = $user->customer;

        if (! $customer || $appointment->customer_id !== $customer->getKey()) {
            abort(404);
        }

        $token = QrCodeToken::query()
            ->where('appointment_id', $appointment->getKey())
            ->latest()
            ->first();

        return $this->respond([
            'id' => $appointment->getKey(),
            'booking_code' => BookingCodeFormatter::appointmentDisplayCode($appointment),
            'service' => $appointment->service?->service_name ?? '',
            'branch' => $appointment->branch?->branch_name ?? '',
            'branch_address' => $appointment->branch?->branch_address ?? '',
            'appointment_date' => $appointment->appointment_date,
            'appointment_time' => $appointment->appointment_time,
            'status' => $this->ticketStatus($appointment),
            'access_key' => $token?->token_value ?? '',
        ]);
    }

    protected function ticketStatus(Appointment $appointment): string
    {
        $latestQueueEntry = $appointment->queueEntries
            ->sortByDesc('updated_at')
            ->first();

        if ($latestQueueEntry) {
            $queueStatus = is_object($latestQueueEntry->queue_status)
                ? (string) ($latestQueueEntry->queue_status->value ?? '')
                : (string) $latestQueueEntry->queue_status;

            if ($queueStatus === 'completed') {
                return 'past';
            }

            if ($queueStatus === 'cancelled') {
                return 'cancelled';
            }
        }

        $rawStatus = is_object($appointment->appointment_status)
            ? (string) ($appointment->appointment_status->value ?? '')
            : (string) $appointment->appointment_status;

        if ($rawStatus === 'cancelled') {
            return 'cancelled';
        }

        if ($rawStatus === 'no_show') {
            return 'past';
        }

        $date = $appointment->appointment_date
            ? Carbon::parse($appointment->appointment_date)->startOfDay()
            : null;

        if ($date && $date->lt(now()->startOfDay())) {
            return 'past';
        }

        return 'upcoming';
    }

    protected function queueTicketStatus(QueueEntry $entry, string $sessionDate): string
    {
        $queueStatus = is_object($entry->queue_status)
            ? (string) ($entry->queue_status->value ?? '')
            : (string) $entry->queue_status;

        if ($queueStatus === 'cancelled') {
            return 'cancelled';
        }

        if ($queueStatus === 'completed') {
            return 'past';
        }

        if ($entry->queueSession?->session_date) {
            $date = Carbon::parse($entry->queueSession->session_date)->startOfDay();
            if ($date->lt(now()->startOfDay())) {
                return 'past';
            }
        } elseif ($sessionDate !== '--') {
            try {
                $date = Carbon::parse($sessionDate)->startOfDay();
                if ($date->lt(now()->startOfDay())) {
                    return 'past';
                }
            } catch (\Throwable $e) {
                // Ignore invalid date parse.
            }
        }

        return 'upcoming';
    }

    /**
     * @param array<int, string> $appointmentIds
     * @return array<string, QrCodeToken>
     */
    protected function latestAppointmentTokens(array $appointmentIds): array
    {
        if ($appointmentIds === []) {
            return [];
        }

        /** @var \Illuminate\Support\Collection<int, QrCodeToken> $tokens */
        $tokens = QrCodeToken::query()
            ->whereIn('appointment_id', $appointmentIds)
            ->orderByDesc('created_at')
            ->get(['id', 'appointment_id', 'token_value', 'expiration_date_time']);

        $resolved = [];
        foreach ($tokens as $token) {
            $appointmentId = (string) $token->appointment_id;
            if ($appointmentId === '' || isset($resolved[$appointmentId])) {
                continue;
            }
            $resolved[$appointmentId] = $token;
        }

        return $resolved;
    }

    /**
     * @param array<int, string> $ticketIds
     * @return array<string, QrCodeToken>
     */
    protected function latestWalkInTokens(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        /** @var \Illuminate\Support\Collection<int, QrCodeToken> $tokens */
        $tokens = QrCodeToken::query()
            ->whereIn('ticket_id', $ticketIds)
            ->orderByDesc('created_at')
            ->get(['id', 'ticket_id', 'token_value', 'expiration_date_time']);

        $resolved = [];
        foreach ($tokens as $token) {
            $ticketId = (string) $token->ticket_id;
            if ($ticketId === '' || isset($resolved[$ticketId])) {
                continue;
            }
            $resolved[$ticketId] = $token;
        }

        return $resolved;
    }
}
