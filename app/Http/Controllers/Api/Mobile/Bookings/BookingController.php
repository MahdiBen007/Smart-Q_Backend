<?php

namespace App\Http\Controllers\Api\Mobile\Bookings;

use App\Enums\TokenStatus;
use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Http\Requests\Api\Mobile\Bookings\CancelBookingRequest;
use App\Http\Requests\Api\Mobile\Bookings\ConfirmBookingRequest;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\QrCodeToken;
use App\Models\User;
use App\Support\Dashboard\BookingCodeFormatter;
use App\Support\Dashboard\OperationalWorkflowService;
use App\Support\Operations\OperationsScheduleTimeSlotService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingController extends MobileApiController
{
    public function __construct(
        protected OperationalWorkflowService $workflow,
        protected OperationsScheduleTimeSlotService $timeSlots,
    ) {}

    public function confirm(ConfirmBookingRequest $request)
    {
        /** @var User $user */
        $user = $request->user();

        $customer = $user->customer;

        if (! $customer) {
            $customer = Customer::query()->create([
                'user_id' => $user->getKey(),
                'full_name' => $request->string('full_name')->value() ?: 'Customer',
                'phone_number' => $user->phone_number ?? '',
                'email_address' => $user->email,
            ]);
        }

        $appointmentDate = Carbon::parse((string) $request->input('appointment_date'))
            ->toDateString();
        $serviceId = $request->string('service_id')->value();

        $this->purgeIncompleteAppointments($customer, $serviceId, $appointmentDate);

        $alreadyBookedToday = Appointment::query()
            ->where('customer_id', $customer->getKey())
            ->where('service_id', $serviceId)
            ->whereDate('appointment_date', $appointmentDate)
            ->where('appointment_status', '!=', 'cancelled')
            ->exists();

        if ($alreadyBookedToday) {
            return $this->respondValidationError(
                'You already have a booking for this service today. Cancel it first to create a new one.',
                [
                    'service_id' => [
                        'One booking per service is allowed per day unless the existing booking is cancelled.',
                    ],
                ],
            );
        }

        $branchId = $request->string('branch_id')->value();
        $appointmentTime = (string) $request->input('appointment_time');
        $bookingChannel = $request->string('booking_channel', 'remote')->value();

        try {
            ['appointment' => $appointment, 'qrToken' => $qrToken] = DB::transaction(function () use (
                $appointmentDate,
                $appointmentTime,
                $branchId,
                $customer,
                $serviceId,
                $bookingChannel,
            ): array {
                DB::table('branches')
                    ->where('id', $branchId)
                    ->lockForUpdate()
                    ->first();

                $bookableSlot = $this->timeSlots->ensureSlotIsBookable(
                    branchId: $branchId,
                    serviceId: $serviceId,
                    date: Carbon::parse($appointmentDate),
                    time: $appointmentTime,
                    bookingChannel: $bookingChannel,
                );

                $appointment = Appointment::query()->create([
                    'customer_id' => $customer->getKey(),
                    'branch_id' => $branchId,
                    'service_id' => $serviceId,
                    'appointment_date' => $appointmentDate,
                    'appointment_time' => $bookableSlot['time'] ?? $appointmentTime,
                    'appointment_end_time' => $bookableSlot['end_time'] ?? null,
                    'appointment_time_label' => $bookableSlot['label'] ?? null,
                    'appointment_session_id' => $bookableSlot['session_id'] ?? null,
                    'appointment_channel' => $bookableSlot['booking_channel'] ?? $bookingChannel,
                    'appointment_status' => 'pending',
                ]);

                $tokenValue = strtoupper(Str::random(6)).'-'.strtoupper(Str::random(2));
                // QR is valid only on the appointment day.
                $expiresAt = Carbon::parse($appointment->appointment_date)->endOfDay();

                $qrToken = QrCodeToken::query()->create([
                    'appointment_id' => $appointment->getKey(),
                    'token_value' => $tokenValue,
                    'expiration_date_time' => $expiresAt,
                    'token_status' => 'active',
                ]);

                return [
                    'appointment' => $appointment,
                    'qrToken' => $qrToken,
                ];
            });
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $firstError = null;
            foreach ($errors as $messages) {
                if (is_array($messages) && $messages !== []) {
                    $firstError = (string) ($messages[0] ?? null);
                    break;
                }
            }

            return $this->respondValidationError(
                $firstError ?: 'Selected appointment slot is not available.',
                $errors,
            );
        }

        $this->flushMobileRealtimeCaches($user);

        return $this->respond([
            'appointment_id' => $appointment->getKey(),
            'booking_code' => BookingCodeFormatter::appointmentDisplayCode($appointment),
            'access_key' => $qrToken->token_value,
            'status' => $appointment->appointment_status,
        ], 'Booking created successfully.', 201);
    }

    public function cancel(CancelBookingRequest $request)
    {
        /** @var User $user */
        $user = $request->user();
        $customer = $user->customer;

        $appointment = Appointment::query()
            ->whereKey($request->string('appointment_id')->value())
            ->firstOrFail();

        if ($customer && $appointment->customer_id !== $customer->getKey()) {
            abort(404);
        }

        $appointment->update([
            'appointment_status' => 'cancelled',
        ]);

        // Ensure cancelled mobile bookings are removed from active queue monitor.
        $this->workflow->cancelAppointmentQueueEntries($appointment);
        $appointment->qrCodeTokens()
            ->where('token_status', TokenStatus::Active->value)
            ->update([
                'token_status' => TokenStatus::Expired,
            ]);
        $this->flushMobileRealtimeCaches($user);

        return $this->respond(message: 'Booking cancelled successfully.');
    }

    protected function flushMobileRealtimeCaches(User $user): void
    {
        Cache::forget(sprintf('mobile:dashboard:%s', $user->getKey()));
        Cache::forget(sprintf('mobile:tickets:%s', $user->getKey()));
        Cache::forget(sprintf('mobile:notifications:%s', $user->getKey()));
    }

    protected function purgeIncompleteAppointments(
        Customer $customer,
        string $serviceId,
        string $appointmentDate,
    ): void {
        Appointment::query()
            ->where('customer_id', $customer->getKey())
            ->where('service_id', $serviceId)
            ->whereDate('appointment_date', $appointmentDate)
            ->where('appointment_status', '!=', 'cancelled')
            ->whereDoesntHave('qrCodeTokens')
            ->whereDoesntHave('queueEntries')
            ->delete();
    }
}
