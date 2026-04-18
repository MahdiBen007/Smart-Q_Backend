<?php

namespace App\Observers;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\Appointment;
use App\Models\Notification;
use App\Support\Dashboard\OperationalWorkflowService;

class AppointmentObserver
{
    public function created(Appointment $appointment): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        app(OperationalWorkflowService::class)->enqueueTodayAppointment($appointment);
    }

    public function updated(Appointment $appointment): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if (
            $appointment->wasChanged('appointment_status')
            && ! $this->shouldSuppressStatusNotification()
        ) {
            $this->notifyAppointmentStatusChanged($appointment);
        }

        if (! $appointment->wasChanged(['appointment_date', 'appointment_status', 'branch_id', 'service_id'])) {
            return;
        }

        app(OperationalWorkflowService::class)->enqueueTodayAppointment($appointment->fresh());
    }

    protected function shouldSuppressStatusNotification(): bool
    {
        if (! app()->bound('request')) {
            return false;
        }

        $request = request();
        if (! $request) {
            return false;
        }

        // Skip local-app driven updates (mobile API actions).
        return $request->is('api/mobile/*');
    }

    protected function notifyAppointmentStatusChanged(Appointment $appointment): void
    {
        $appointment->loadMissing(['customer.user', 'service', 'branch']);

        $userId = $appointment->customer?->user_id;
        if (! is_string($userId) || trim($userId) === '') {
            return;
        }

        $status = is_object($appointment->appointment_status)
            ? (string) ($appointment->appointment_status->value ?? '')
            : (string) $appointment->appointment_status;

        $serviceName = $appointment->service?->service_name ?? 'service';
        $branchName = $appointment->branch?->branch_name ?? 'branch';

        [$title, $description, $tone] = match ($status) {
            'cancelled' => [
                'Booking Cancelled',
                "Your reservation at {$branchName} has been cancelled as requested.",
                'critical',
            ],
            'no_show' => [
                'Appointment marked no-show',
                "You were marked as no-show for {$serviceName} at {$branchName}.",
                'warning',
            ],
            'active' => [
                'Appointment is now active',
                "Your {$serviceName} appointment at {$branchName} is now active.",
                'success',
            ],
            'confirmed' => [
                'Appointment confirmed',
                "Your {$serviceName} appointment at {$branchName} is confirmed.",
                'success',
            ],
            'pending' => [
                'Appointment updated',
                "Your {$serviceName} appointment at {$branchName} is pending.",
                'info',
            ],
            default => [
                'Appointment updated',
                "Your {$serviceName} appointment at {$branchName} was updated.",
                'info',
            ],
        };

        Notification::query()->create([
            'user_id' => $userId,
            'notification_type' => 'booking',
            'title' => $title,
            'description' => $description,
            'tone' => $tone,
            'action_path' => '/my-tickets',
            'occurred_at' => now(),
            'notification_channel' => NotificationChannel::InApp,
            'delivery_status' => NotificationDeliveryStatus::Sent,
            'message_content' => $description,
            'read_at' => null,
        ]);
    }
}
