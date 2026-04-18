<?php

namespace App\Http\Controllers\Api\Mobile\Tickets;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Models\Appointment;
use App\Models\QrCodeToken;
use App\Models\QueueEntry;
use App\Models\User;
use App\Support\Dashboard\BookingCodeFormatter;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TicketController extends MobileApiController
{
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $customer = $user->customer;

        if (! $customer) {
            return $this->respond([]);
        }

        $appointments = Appointment::query()
            ->with(['branch', 'service', 'customer.user', 'queueEntries'])
            ->where('customer_id', $customer->getKey())
            ->latest('appointment_date')
            ->get();

        $appointmentTickets = $appointments->map(function (Appointment $appointment) {
            $token = QrCodeToken::query()
                ->where('appointment_id', $appointment->getKey())
                ->latest()
                ->first();

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
            ->with(['walkInTicket.branch', 'walkInTicket.service', 'walkInTicket.customer.user', 'queueSession', 'customer.user', 'appointment.customer.user'])
            ->where('customer_id', $customer->getKey())
            ->whereNotNull('ticket_id')
            ->latest('updated_at')
            ->get();

        $walkInTickets = $queueEntries->map(function (QueueEntry $entry) {
            $ticket = $entry->walkInTicket;

            if (! $ticket) {
                return null;
            }

            $token = QrCodeToken::query()
                ->where('ticket_id', $ticket->getKey())
                ->latest()
                ->first();

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

        $tickets = $appointmentTickets
            ->concat($walkInTickets)
            ->sortByDesc('date_line_1')
            ->values()
            ->all();

        return $this->respond($tickets);
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
}
