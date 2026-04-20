<?php

namespace App\Http\Controllers\Api\Mobile\Dashboard;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Models\Appointment;
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
                    $latestQueueEntry = QueueEntry::query()
                        ->with(['appointment.branch.company', 'appointment.service', 'appointment.customer.user'])
                        ->whereHas('appointment', function ($query) use ($customer) {
                            $query
                                ->where('customer_id', $customer->getKey())
                                ->whereNotIn('appointment_status', ['cancelled', 'no_show']);
                        })
                        ->orderByDesc('updated_at')
                        ->first();

                    $appointment = $latestQueueEntry?->appointment;

                    if (! $appointment) {
                        $appointment = Appointment::query()
                            ->with(['branch.company', 'service', 'customer.user'])
                            ->where('customer_id', $customer->getKey())
                            ->whereNotIn('appointment_status', ['cancelled', 'no_show'])
                            ->orderByDesc('appointment_date')
                            ->orderByDesc('updated_at')
                            ->first();
                    } else {
                        $appointment->loadMissing(['branch.company', 'service', 'customer.user']);
                    }

                    if ($appointment) {
                        $accessToken = QrCodeToken::query()
                            ->where('appointment_id', $appointment->getKey())
                            ->latest()
                            ->first();

                        $dateTime = $appointment->appointment_date
                            ? Carbon::parse($appointment->appointment_date)->toDateString()
                            : null;

                        if ($appointment->appointment_time) {
                            $dateTime = Carbon::parse(
                                $appointment->appointment_date.' '.$appointment->appointment_time
                            )->toDateTimeString();
                        }

                        $status = is_object($appointment->appointment_status)
                            ? (string) ($appointment->appointment_status->value ?? '')
                            : (string) $appointment->appointment_status;

                        $hasCompletedQueueEntry = QueueEntry::query()
                            ->where('appointment_id', $appointment->getKey())
                            ->where('queue_status', 'completed')
                            ->exists();

                        $entryForStatus = $latestQueueEntry;
                        if (
                            ! $entryForStatus
                            || $entryForStatus->appointment_id !== $appointment->getKey()
                        ) {
                            $entryForStatus = QueueEntry::query()
                                ->where('appointment_id', $appointment->getKey())
                                ->orderByDesc('updated_at')
                                ->first();
                        }

                        if ($entryForStatus) {
                            $queueStatus = is_object($entryForStatus->queue_status)
                                ? (string) ($entryForStatus->queue_status->value ?? '')
                                : (string) $entryForStatus->queue_status;

                            if ($queueStatus === 'completed') {
                                $status = 'completed';
                            } elseif (in_array($queueStatus, ['serving', 'next'], true)) {
                                $status = 'serving';
                            } elseif ($entryForStatus->checked_in_at !== null) {
                                $status = 'confirmed';
                            }
                        }

                        if ($hasCompletedQueueEntry) {
                            $status = 'completed';
                        }

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
                            'arrival_window' => $appointment->appointment_time
                                ? Carbon::parse($appointment->appointment_time)->format('g:i A').' - '
                                    .Carbon::parse($appointment->appointment_time)->addMinutes(30)->format('g:i A')
                                : '',
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
}
