<?php

namespace App\Http\Controllers\Api\Mobile\Dashboard;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\QueueEntry;
use App\Models\QrCodeToken;
use App\Models\User;
use App\Support\Dashboard\BookingCodeFormatter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends MobileApiController
{
    public function show(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $payload = Cache::remember(
            sprintf('mobile:dashboard:%s', $user->getKey()),
            now()->addSeconds(8),
            function () use ($user): array {
                $customer = $user->customer;
                $activeBooking = null;

                if ($customer) {
                    $context = $this->resolveActiveAppointmentContext($customer);
                    $appointment = $context['appointment'] ?? null;
                    $entryForStatus = $context['queue_entry'] ?? null;

                    if ($appointment) {
                        $accessToken = QrCodeToken::query()
                            ->where('appointment_id', $appointment->getKey())
                            ->latest()
                            ->first();

                        $dateTime = $this->formatAppointmentDateTime($appointment);

                        $status = $this->resolveDashboardStatus($appointment, $entryForStatus);

                        $activeBooking = [
                            'service' => $appointment->service?->service_name ?? '',
                            'duration' => $appointment->service?->average_service_duration_minutes
                                ? $appointment->service->average_service_duration_minutes.' min'
                                : '',
                            'branch' => $appointment->branch?->branch_name ?? '',
                            'company_name' => $appointment->branch?->company?->company_name ?? '',
                            'branch_address' => $appointment->branch?->branch_address ?? '',
                            'date_time' => $dateTime ?? '',
                            'status' => $status,
                            'pass_id' => $appointment->getKey(),
                            'booking_code' => BookingCodeFormatter::appointmentDisplayCode($appointment),
                            'access_key' => $accessToken?->token_value ?? '',
                            'arrival_window' => $this->formatArrivalWindow($appointment),
                            'valid_until' => $accessToken?->expiration_date_time?->toDateTimeString() ?? '',
                        ];
                    }
                }

                return [
                    'user_name' => $customer?->full_name ?? '',
                    'email' => $user->email ?? $customer?->email_address ?? '',
                    'phone' => $user->phone_number ?? $customer?->phone_number ?? '',
                    'active_booking' => $activeBooking ?? [],
                ];
            }
        );

        return $this->respond($payload);
    }

    /**
     * @return array{appointment: Appointment, queue_entry: QueueEntry|null}|null
     */
    protected function resolveActiveAppointmentContext(Customer $customer): ?array
    {
        $activeQueueEntry = QueueEntry::query()
            ->with(['appointment.branch.company', 'appointment.service', 'appointment.customer.user'])
            ->where('customer_id', $customer->getKey())
            ->whereNotIn('queue_status', ['completed', 'cancelled'])
            ->whereHas('appointment', function ($query) use ($customer) {
                $query
                    ->where('customer_id', $customer->getKey())
                    ->whereNotIn('appointment_status', ['cancelled', 'no_show']);
            })
            ->orderByRaw(
                "CASE queue_status
                    WHEN 'serving' THEN 0
                    WHEN 'next' THEN 1
                    WHEN 'waiting' THEN 2
                    ELSE 3
                END"
            )
            ->orderByDesc('checked_in_at')
            ->orderByDesc('updated_at')
            ->first();

        $activeAppointment = $activeQueueEntry?->appointment;
        if ($activeAppointment) {
            return [
                'appointment' => $activeAppointment,
                'queue_entry' => $activeQueueEntry,
            ];
        }

        $upcomingAppointment = Appointment::query()
            ->with([
                'branch.company',
                'service',
                'customer.user',
                'queueEntries:id,appointment_id,queue_status,checked_in_at,updated_at',
            ])
            ->where('customer_id', $customer->getKey())
            ->whereNotIn('appointment_status', ['cancelled', 'no_show'])
            ->whereDate('appointment_date', '>=', now()->toDateString())
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->orderByDesc('updated_at')
            ->first();

        if (! $upcomingAppointment) {
            return null;
        }

        return [
            'appointment' => $upcomingAppointment,
            'queue_entry' => $this->latestRelevantQueueEntry($upcomingAppointment),
        ];
    }

    protected function latestRelevantQueueEntry(Appointment $appointment): ?QueueEntry
    {
        if (! $appointment->relationLoaded('queueEntries')) {
            $appointment->load([
                'queueEntries:id,appointment_id,queue_status,checked_in_at,updated_at',
            ]);
        }

        return $appointment->queueEntries
            ->sortByDesc('updated_at')
            ->first(function (QueueEntry $entry): bool {
                return ! in_array($this->queueStatusValue($entry), ['completed', 'cancelled'], true);
            });
    }

    protected function resolveDashboardStatus(Appointment $appointment, ?QueueEntry $queueEntry): string
    {
        $appointmentStatus = $this->appointmentStatusValue($appointment);
        $queueStatus = $queueEntry ? $this->queueStatusValue($queueEntry) : '';

        if (in_array($queueStatus, ['serving', 'next'], true) || $appointmentStatus === 'active') {
            return 'serving';
        }

        if (
            $queueStatus === 'waiting'
            && $queueEntry?->checked_in_at !== null
        ) {
            return 'confirmed';
        }

        if (in_array($appointmentStatus, ['confirmed', 'pending'], true)) {
            return $appointmentStatus;
        }

        return $appointmentStatus !== '' ? $appointmentStatus : 'pending';
    }

    protected function appointmentStatusValue(Appointment $appointment): string
    {
        return is_object($appointment->appointment_status)
            ? (string) ($appointment->appointment_status->value ?? '')
            : (string) $appointment->appointment_status;
    }

    protected function queueStatusValue(QueueEntry $entry): string
    {
        return is_object($entry->queue_status)
            ? (string) ($entry->queue_status->value ?? '')
            : (string) $entry->queue_status;
    }

    protected function formatAppointmentDateTime(Appointment $appointment): string
    {
        $date = $appointment->appointment_date;
        if (! $date) {
            return '';
        }

        $resolvedDate = $date instanceof Carbon
            ? $date->copy()
            : Carbon::parse((string) $date);

        $time = trim((string) $appointment->appointment_time);
        if ($time === '') {
            return $resolvedDate->format('M d, Y');
        }

        try {
            return $resolvedDate
                ->setTimeFromTimeString($time)
                ->format('M d, Y g:i A');
        } catch (\Throwable) {
            return $resolvedDate->format('M d, Y');
        }
    }

    protected function formatArrivalWindow(Appointment $appointment): string
    {
        $time = trim((string) $appointment->appointment_time);
        if ($time === '') {
            return '';
        }

        try {
            $start = Carbon::createFromFormat('H:i:s', $time);
        } catch (\Throwable) {
            try {
                $start = Carbon::createFromFormat('H:i', $time);
            } catch (\Throwable) {
                return '';
            }
        }

        return $start->format('g:i A').' - '.$start->copy()->addMinutes(30)->format('g:i A');
    }
}
