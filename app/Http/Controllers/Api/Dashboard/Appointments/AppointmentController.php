<?php

namespace App\Http\Controllers\Api\Dashboard\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\QueueEntryStatus;
use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Support\Dashboard\DashboardFormatting;
use App\Support\Dashboard\OperationalWorkflowService;

class AppointmentController extends DashboardApiController
{
    public function __construct(
        protected OperationalWorkflowService $workflow,
    ) {}

    public function bootstrap()
    {
        $appointments = $this->baseQuery()->get();

        return $this->respond([
            'appointments' => $appointments->map(fn (Appointment $appointment) => $this->transformAppointment($appointment))->values()->all(),
            'defaultSelectedId' => $appointments->first()?->getKey(),
        ]);
    }

    public function index()
    {
        return $this->respond(
            $this->baseQuery()
                ->get()
                ->map(fn (Appointment $appointment) => $this->transformAppointment($appointment))
                ->values()
                ->all()
        );
    }

    public function show(Appointment $appointment)
    {
        $appointment->loadMissing($this->appointmentRelations());

        return $this->respond($this->transformAppointment($appointment));
    }

    public function openTicket(Appointment $appointment)
    {
        $this->workflow->openAppointmentTicket(
            $appointment->loadMissing(['branch', 'service', 'customer'])
        );

        return $this->respond(
            $this->transformAppointment($appointment->fresh($this->appointmentRelations())),
            'Appointment ticket opened successfully.'
        );
    }

    public function markNoShow(Appointment $appointment)
    {
        $appointment->update([
            'appointment_status' => AppointmentStatus::NoShow,
        ]);

        QueueEntry::query()
            ->where('appointment_id', $appointment->getKey())
            ->whereNotIn('queue_status', [QueueEntryStatus::Completed, QueueEntryStatus::Cancelled])
            ->update([
                'queue_status' => QueueEntryStatus::Cancelled,
            ]);

        return $this->respond(
            $this->transformAppointment($appointment->fresh($this->appointmentRelations())),
            'Appointment marked as no-show.'
        );
    }

    public function cancel(Appointment $appointment)
    {
        $appointment->update([
            'appointment_status' => AppointmentStatus::Cancelled,
        ]);

        QueueEntry::query()
            ->where('appointment_id', $appointment->getKey())
            ->whereNotIn('queue_status', [QueueEntryStatus::Completed, QueueEntryStatus::Cancelled])
            ->update([
                'queue_status' => QueueEntryStatus::Cancelled,
            ]);

        return $this->respond(
            $this->transformAppointment($appointment->fresh($this->appointmentRelations())),
            'Appointment cancelled successfully.'
        );
    }

    protected function baseQuery()
    {
        return Appointment::query()
            ->with($this->appointmentRelations())
            ->orderByDesc('appointment_date')
            ->orderBy('appointment_time');
    }

    protected function appointmentRelations(): array
    {
        return [
            'customer' => fn ($query) => $query
                ->select(['id', 'full_name', 'email_address', 'phone_number'])
                ->withCount('appointments')
                ->withCount([
                    'appointments as no_show_appointments_count' => fn ($appointmentQuery) => $appointmentQuery
                        ->where('appointment_status', AppointmentStatus::NoShow->value),
                ]),
            'branch:id,branch_name',
            'service:id,service_name,average_service_duration_minutes',
            'staffMember:id,full_name',
            'queueEntries' => fn ($query) => $query
                ->select(['id', 'appointment_id', 'queue_position', 'queue_status', 'checked_in_at', 'created_at'])
                ->latest('created_at'),
        ];
    }

    protected function transformAppointment(Appointment $appointment): array
    {
        $queueEntry = $appointment->queueEntries
            ->sortByDesc('created_at')
            ->first();

        $queueState = match (true) {
            $queueEntry && ! in_array($queueEntry->queue_status->value, ['completed', 'cancelled'], true) => 'Checked In',
            $appointment->appointment_status->value === 'no_show' => 'Expired',
            $appointment->appointment_date && $appointment->appointment_date->isPast() => 'Expired',
            default => 'Not Queued',
        };

        $timeSlot = $appointment->appointment_time
            ? sprintf(
                '%s - %s',
                DashboardFormatting::shortTime($appointment->appointment_time),
                DashboardFormatting::shortTime(
                    $appointment->appointment_date
                        ? $appointment->appointment_date->copy()->setTimeFromTimeString((string) $appointment->appointment_time)->addMinutes(30)
                        : now()->addMinutes(30)
                )
            )
            : '--';

        $visits = (int) ($appointment->customer?->appointments_count ?? 0);
        $noShows = (int) ($appointment->customer?->no_show_appointments_count ?? 0);
        $waitMinutes = $queueEntry ? max(($queueEntry->queue_position - 1) * ($appointment->service?->average_service_duration_minutes ?? 10), 0) : null;

        return [
            'id' => $appointment->getKey(),
            'customerName' => $appointment->customer?->full_name ?? 'Unknown Customer',
            'initials' => DashboardFormatting::initials($appointment->customer?->full_name ?? 'UC'),
            'avatarUrl' => null,
            'branch' => $appointment->branch?->branch_name ?? 'Main Branch',
            'service' => $appointment->service?->service_name ?? 'General Service',
            'date' => DashboardFormatting::shortDate($appointment->appointment_date),
            'time' => DashboardFormatting::shortTime($appointment->appointment_time),
            'status' => DashboardFormatting::appointmentStatusLabel($appointment->appointment_status->value),
            'queueState' => $queueState,
            'email' => $appointment->customer?->email_address ?? 'no-email@smartqdz.local',
            'phone' => $appointment->customer?->phone_number ?? '--',
            'visits' => $visits,
            'noShows' => $noShows,
            'timeSlot' => $timeSlot,
            'staffName' => $appointment->staffMember?->full_name ?? 'Unassigned',
            'staffInitials' => DashboardFormatting::initials($appointment->staffMember?->full_name ?? 'NA'),
            'checkedInAt' => $queueEntry?->checked_in_at ? DashboardFormatting::shortTime($queueEntry->checked_in_at) : null,
            'queuePosition' => $queueEntry && ! in_array($queueEntry->queue_status->value, ['completed', 'cancelled'], true)
                ? '#'.$queueEntry->queue_position
                : null,
            'waitTime' => $waitMinutes !== null ? '~'.$waitMinutes.' min' : null,
        ];
    }
}
