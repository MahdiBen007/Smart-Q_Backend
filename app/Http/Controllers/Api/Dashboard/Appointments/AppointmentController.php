<?php

namespace App\Http\Controllers\Api\Dashboard\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\QueueEntryStatus;
use App\Enums\CheckInResult;
use App\Enums\TokenStatus;
use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\Appointments\CheckInAppointmentRequest;
use App\Http\Requests\Api\Dashboard\Appointments\ListAppointmentsRequest;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\QrCodeToken;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Support\Dashboard\BookingCodeFormatter;
use App\Support\Dashboard\DashboardFormatting;
use App\Support\Dashboard\OperationalWorkflowService;
use Illuminate\Database\Eloquent\Builder;

class AppointmentController extends DashboardApiController
{
    public function __construct(
        protected OperationalWorkflowService $workflow,
    ) {}

    public function bootstrap(ListAppointmentsRequest $request)
    {
        return $this->respond([
            'branches' => $this->branchOptions($request),
            'services' => $this->serviceOptions($request),
        ]);
    }

    public function index(ListAppointmentsRequest $request)
    {
        $query = $this->applyFilters($this->baseQuery($request), $request);
        $summary = $this->appointmentsSummary(clone $query);

        if (! $request->shouldPaginate()) {
            $appointments = (clone $query)
                ->get()
                ->map(fn (Appointment $appointment) => $this->transformAppointment($appointment))
                ->values()
                ->all();

            return $this->respond([
                'appointments' => $appointments,
                'summary' => $summary,
                'defaultSelectedId' => $appointments[0]['id'] ?? null,
            ]);
        }

        $paginator = (clone $query)
            ->paginate(perPage: $request->perPage(20))
            ->appends($request->query());

        $appointments = collect($paginator->items())
            ->map(fn (Appointment $appointment) => $this->transformAppointment($appointment))
            ->values()
            ->all();

        return $this->respond([
            'appointments' => $appointments,
            'summary' => $summary,
            'defaultSelectedId' => $appointments[0]['id'] ?? null,
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    public function show(ListAppointmentsRequest $request, Appointment $appointment)
    {
        $this->ensureCompanyAccess($request, $appointment);
        $appointment->loadMissing($this->appointmentRelations());

        return $this->respond($this->transformAppointment($appointment));
    }

    public function checkIn(CheckInAppointmentRequest $request)
    {
        $validated = $request->validated();
        $scannedToken = QrCodeToken::query()
            ->with('appointment.branch')
            ->where('token_value', $validated['token_value'])
            ->first();

        if ($scannedToken?->appointment) {
            $this->ensureCompanyAccess($request, $scannedToken->appointment);
        }

        $result = $this->workflow->checkInAppointmentToken(
            $validated['token_value'],
            $validated['kiosk_id'] ?? null,
            $validated['result'] ?? CheckInResult::Success->value,
        );

        $appointment = $result['appointment']->loadMissing($this->appointmentRelations());
        $this->ensureCompanyAccess($request, $appointment);
        $this->invalidateDashboardCache($request, $appointment->branch?->company_id);

        return $this->respond([
            'appointment' => $this->transformAppointment($appointment),
            'queueEntryId' => $result['queue_entry']->getKey(),
            'checkInId' => $result['check_in']->getKey(),
            'tokenStatus' => DashboardFormatting::titleCase($result['qr_token']->token_status->value),
        ], 'Appointment checked in successfully.');
    }

    public function simulateCheckIn(ListAppointmentsRequest $request, Appointment $appointment)
    {
        $this->ensureCompanyAccess($request, $appointment);

        $activeQrToken = QrCodeToken::query()
            ->where('appointment_id', $appointment->getKey())
            ->where('token_status', TokenStatus::Active->value)
            ->latest('created_at')
            ->first();

        if (! $activeQrToken) {
            return $this->respond(
                ['errors' => ['appointment' => ['This appointment cannot be checked in right now.']]],
                'No active QR token is available for this appointment.',
                422
            );
        }

        $result = $this->workflow->checkInAppointmentToken(
            $activeQrToken->token_value,
            null,
            CheckInResult::Success->value,
        );

        $nextAppointment = $result['appointment']->loadMissing($this->appointmentRelations());
        $this->ensureCompanyAccess($request, $nextAppointment);
        $this->invalidateDashboardCache($request, $nextAppointment->branch?->company_id);

        return $this->respond(
            $this->transformAppointment($nextAppointment),
            'Appointment check-in simulated successfully.'
        );
    }

    public function markNoShow(Appointment $appointment)
    {
        $request = request();
        $this->ensureCompanyAccess($request, $appointment);
        $appointment->update([
            'appointment_status' => AppointmentStatus::NoShow,
        ]);

        $this->workflow->cancelAppointmentQueueEntries($appointment);
        $appointment->qrCodeTokens()
            ->where('token_status', TokenStatus::Active->value)
            ->update([
                'token_status' => TokenStatus::Expired,
            ]);
        $this->invalidateDashboardCache($request, $appointment->branch?->company_id);

        return $this->respond(
            $this->transformAppointment($appointment->fresh($this->appointmentRelations())),
            'Appointment marked as no-show.'
        );
    }

    public function cancel(Appointment $appointment)
    {
        $request = request();
        $this->ensureCompanyAccess($request, $appointment);
        $appointment->update([
            'appointment_status' => AppointmentStatus::Cancelled,
        ]);

        $this->workflow->cancelAppointmentQueueEntries($appointment);
        $appointment->qrCodeTokens()
            ->where('token_status', TokenStatus::Active->value)
            ->update([
                'token_status' => TokenStatus::Expired,
            ]);
        $this->invalidateDashboardCache($request, $appointment->branch?->company_id);

        return $this->respond(
            $this->transformAppointment($appointment->fresh($this->appointmentRelations())),
            'Appointment cancelled successfully.'
        );
    }

    public function destroy(ListAppointmentsRequest $request, Appointment $appointment)
    {
        $this->ensureCompanyAccess($request, $appointment);
        $companyId = $appointment->branch?->company_id;

        $this->workflow->cancelAppointmentQueueEntries($appointment);
        $appointment->qrCodeTokens()
            ->where('token_status', TokenStatus::Active->value)
            ->update([
                'token_status' => TokenStatus::Expired,
            ]);

        $deletedId = $appointment->getKey();
        // Keep relational history (check-ins/QR tokens) intact; hard delete can
        // violate FK constraints when check_in_records reference qr_code_tokens.
        $appointment->delete();
        $this->invalidateDashboardCache($request, $companyId);

        return $this->respond([
            'id' => $deletedId,
            'deleted' => true,
        ], 'Appointment deleted successfully.');
    }

    protected function baseQuery(ListAppointmentsRequest $request): Builder
    {
        $today = now()->toDateString();

        $query = $this->scopeQueryByCompanyRelation(
            Appointment::query()
                ->with($this->appointmentRelations())
                ->orderByRaw(
                    'CASE
                        WHEN appointment_date = ? THEN 0
                        WHEN appointment_date > ? THEN 1
                        ELSE 2
                    END',
                    [$today, $today]
                )
                ->orderByRaw(
                    'CASE
                        WHEN appointment_date >= ? THEN appointment_date
                    END ASC',
                    [$today]
                )
                ->orderByRaw(
                    'CASE
                        WHEN appointment_date < ? THEN appointment_date
                    END DESC',
                    [$today]
                )
                ->orderBy('appointment_time'),
            $request,
            'branch'
        );

        $query = $this->scopeQueryByAssignedBranchColumn($query, $request);

        return $this->scopeQueryByAssignedServiceColumn($query, $request);
    }

    protected function appointmentRelations(): array
    {
        return [
            'customer' => fn ($query) => $query
                ->select(['id', 'user_id', 'full_name', 'email_address', 'phone_number'])
                ->with('user:id,user_type')
                ->withCount('appointments')
                ->withCount([
                    'appointments as no_show_appointments_count' => fn ($appointmentQuery) => $appointmentQuery
                        ->where('appointment_status', AppointmentStatus::NoShow->value),
                ]),
            'branch' => fn ($query) => $query
                ->select(['id', 'company_id', 'branch_name'])
                ->with('company:id,company_name'),
            'service:id,service_name,average_service_duration_minutes',
            'staffMember:id,full_name',
            'qrCodeTokens' => fn ($query) => $query
                ->select(['id', 'appointment_id', 'token_status', 'expiration_date_time', 'used_date_time', 'created_at'])
                ->latest('created_at'),
            'queueEntries' => fn ($query) => $query
                ->select(['id', 'appointment_id', 'queue_position', 'queue_status', 'checked_in_at', 'created_at'])
                ->latest('created_at'),
        ];
    }

    protected function applyFilters(Builder $query, ListAppointmentsRequest $request): Builder
    {
        $search = trim((string) $request->input('search', ''));
        $branchId = $request->input('branch_id');
        $serviceId = $request->input('service_id');
        $status = $request->input('status');
        $queueState = $request->input('queue_state');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        if (is_string($branchId) && $branchId !== '') {
            $query->where('branch_id', $branchId);
        }

        if (is_string($serviceId) && $serviceId !== '') {
            $query->where('service_id', $serviceId);
        }

        if (is_string($status) && $status !== '') {
            if ($status === 'Completed') {
                $query->whereHas(
                    'queueEntries',
                    fn (Builder $queueQuery) => $queueQuery->where('queue_status', QueueEntryStatus::Completed->value)
                );
            } else {
                $query->where('appointment_status', $this->appointmentStatusValue($status));
            }
        }

        if (is_string($dateFrom) && $dateFrom !== '') {
            $query->whereDate('appointment_date', '>=', $dateFrom);
        }

        if (is_string($dateTo) && $dateTo !== '') {
            $query->whereDate('appointment_date', '<=', $dateTo);
        }

        if (is_string($queueState) && $queueState !== '') {
            match ($queueState) {
                'Checked In' => $query->whereHas(
                    'queueEntries',
                    fn (Builder $queueQuery) => $this->applyCheckedInQueueConstraint($queueQuery)
                ),
                'Expired' => $query
                    ->where(fn (Builder $expiredQuery) => $this->applyExpiredAppointmentConstraint($expiredQuery)),
                'Awaiting Check-In', 'Not Queued' => $query
                    ->whereDoesntHave(
                        'queueEntries',
                        fn (Builder $queueQuery) => $this->applyCheckedInQueueConstraint($queueQuery)
                    )
                    ->where(fn (Builder $upcomingQuery) => $this->applyNotExpiredAppointmentConstraint($upcomingQuery)),
            };
        }

        if ($search !== '') {
            $this->applySearchFilter($query, $search);
        }

        return $query;
    }

    protected function applySearchFilter(Builder $query, string $search): void
    {
        $displayIdMatches = $this->matchingDisplayIds(clone $query, $search);

        $query->where(function (Builder $searchQuery) use ($search, $displayIdMatches): void {
            $searchQuery
                ->whereHas('customer', function (Builder $customerQuery) use ($search): void {
                    $customerQuery
                        ->where('full_name', 'like', '%'.$search.'%')
                        ->orWhere('email_address', 'like', '%'.$search.'%')
                        ->orWhere('phone_number', 'like', '%'.$search.'%');
                })
                ->orWhereHas('service', fn (Builder $serviceQuery) => $serviceQuery->where('service_name', 'like', '%'.$search.'%'))
                ->orWhereHas('branch', fn (Builder $branchQuery) => $branchQuery->where('branch_name', 'like', '%'.$search.'%'))
                ->orWhereHas('staffMember', fn (Builder $staffQuery) => $staffQuery->where('full_name', 'like', '%'.$search.'%'));

            if ($displayIdMatches !== []) {
                $searchQuery->orWhereIn('appointments.id', $displayIdMatches);
            }
        });
    }

    protected function matchingDisplayIds(Builder $query, string $search): array
    {
        $normalizedSearch = strtoupper(str_replace(' ', '', $search));

        if ($normalizedSearch === '') {
            return [];
        }

        $matchedIds = [];

        (clone $query)
            ->select([
                'appointments.id',
                'appointments.customer_id',
                'appointments.branch_id',
                'appointments.service_id',
                'appointments.appointment_date',
            ])
            ->with([
                'customer:id,user_id',
                'customer.user:id,user_type',
                'branch:id,company_id,branch_name',
                'branch.company:id,company_name',
                'service:id,service_name',
            ])
            ->chunk(200, function ($appointments) use (&$matchedIds, $normalizedSearch): void {
                foreach ($appointments as $appointment) {
                    $displayCode = strtoupper(str_replace(' ', '', $this->appointmentDisplayId($appointment)));
                    $shortCode = strtoupper(str_replace(' ', '', BookingCodeFormatter::appointmentShortCode($appointment)));

                    if (
                        str_contains($displayCode, $normalizedSearch)
                        || str_contains($shortCode, $normalizedSearch)
                    ) {
                        $matchedIds[] = $appointment->getKey();
                    }
                }
            });

        return $matchedIds;
    }

    protected function branchOptions(ListAppointmentsRequest $request): array
    {
        $query = $this->scopeQueryByCompanyColumn(
            Branch::query()->orderBy('branch_name'),
            $request
        );
        $query = $this->scopeQueryByAssignedBranchColumn($query, $request, 'id');

        return $query->get(['id', 'branch_name'])
            ->map(fn (Branch $branch) => [
                'id' => $branch->getKey(),
                'label' => $branch->branch_name,
            ])
            ->values()
            ->all();
    }

    protected function serviceOptions(ListAppointmentsRequest $request): array
    {
        $query = $this->scopeQueryByCompanyRelation(
            Service::query()->orderBy('service_name'),
            $request,
            'branches'
        );
        $query = $this->scopeQueryByAssignedBranchRelation($query, $request, 'branches');
        $query = $this->scopeQueryByAssignedServiceColumn($query, $request, 'id');

        return $query->get(['id', 'service_name'])
            ->unique('id')
            ->map(fn (Service $service) => [
                'id' => $service->getKey(),
                'label' => $service->service_name,
            ])
            ->values()
            ->all();
    }

    protected function appointmentsSummary(Builder $query): array
    {
        $totalAppointments = (clone $query)->count();
        $confirmedCount = (clone $query)
            ->where('appointment_status', AppointmentStatus::Confirmed->value)
            ->count();
        $noShowCount = (clone $query)
            ->where('appointment_status', AppointmentStatus::NoShow->value)
            ->count();
        $checkedInCount = (clone $query)
            ->whereHas(
                'queueEntries',
                fn (Builder $queueQuery) => $this->applyCheckedInQueueConstraint($queueQuery)
            )
            ->count();

        return [
            'totalAppointments' => $totalAppointments,
            'confirmedCount' => $confirmedCount,
            'noShowCount' => $noShowCount,
            'checkedInCount' => $checkedInCount,
            'noShowRate' => $totalAppointments > 0 ? round(($noShowCount / $totalAppointments) * 100, 1) : 0,
            'confirmedRate' => $totalAppointments > 0 ? (int) round(($confirmedCount / $totalAppointments) * 100) : 0,
            'convertedRate' => $totalAppointments > 0 ? (int) round(($checkedInCount / $totalAppointments) * 100) : 0,
        ];
    }

    protected function appointmentStatusValue(string $status): string
    {
        return match ($status) {
            'No-Show' => AppointmentStatus::NoShow->value,
            default => strtolower($status),
        };
    }

    protected function applyCheckedInQueueConstraint(Builder $query): void
    {
        $query->whereNotNull('checked_in_at');
    }

    protected function applyExpiredAppointmentConstraint(Builder $query): void
    {
        $now = now();
        $today = now()->toDateString();
        $currentTime = $now->format('H:i:s');

        $query
            ->where('appointment_status', AppointmentStatus::NoShow->value)
            ->orWhere('appointment_status', AppointmentStatus::Cancelled->value)
            ->orWhereDate('appointment_date', '<', $today)
            ->orWhere(function (Builder $sameDayQuery) use ($today, $currentTime): void {
                $sameDayQuery
                    ->whereDate('appointment_date', '=', $today)
                    ->whereNotNull('appointment_time')
                    ->whereTime('appointment_time', '<', $currentTime);
            });
    }

    protected function applyNotExpiredAppointmentConstraint(Builder $query): void
    {
        $now = now();
        $today = now()->toDateString();
        $currentTime = $now->format('H:i:s');

        $query
            ->whereNotIn('appointment_status', [
                AppointmentStatus::NoShow->value,
                AppointmentStatus::Cancelled->value,
            ])
            ->where(function (Builder $upcomingQuery) use ($today, $currentTime): void {
                $upcomingQuery
                    ->whereDate('appointment_date', '>', $today)
                    ->orWhere(function (Builder $sameDayQuery) use ($today, $currentTime): void {
                        $sameDayQuery
                            ->whereDate('appointment_date', '=', $today)
                            ->where(function (Builder $timeQuery) use ($currentTime): void {
                                $timeQuery
                                    ->whereNull('appointment_time')
                                    ->orWhereTime('appointment_time', '>=', $currentTime);
                            });
                    });
            });
    }

    protected function appointmentHasExpired(Appointment $appointment): bool
    {
        if (
            in_array(
                $appointment->appointment_status->value,
                [AppointmentStatus::NoShow->value, AppointmentStatus::Cancelled->value],
                true
            )
        ) {
            return true;
        }

        if (! $appointment->appointment_date) {
            return false;
        }

        $appointmentDateTime = $appointment->appointment_time
            ? $appointment->appointment_date->copy()->setTimeFromTimeString((string) $appointment->appointment_time)
            : $appointment->appointment_date->copy()->endOfDay();

        return $appointmentDateTime->isPast();
    }

    protected function queueStateFor(Appointment $appointment): string
    {
        $hasArrivedQueueState = $appointment->queueEntries->contains(
            fn (QueueEntry $entry): bool => $entry->checked_in_at !== null
        );

        return match (true) {
            $hasArrivedQueueState => 'Checked In',
            $this->appointmentHasExpired($appointment) => 'Expired',
            default => 'Awaiting Check-In',
        };
    }

    protected function appointmentDisplayId(Appointment $appointment): string
    {
        return BookingCodeFormatter::appointmentDisplayCode($appointment);
    }

    protected function transformAppointment(Appointment $appointment): array
    {
        $latestQueueEntry = $appointment->queueEntries
            ->sortByDesc('created_at')
            ->first();
        $activeQueueEntry = $appointment->queueEntries
            ->first(function (QueueEntry $entry): bool {
                $status = $entry->queue_status->value;

                return ! in_array($status, [
                    QueueEntryStatus::Completed->value,
                    QueueEntryStatus::Cancelled->value,
                ], true);
            });
        $queueEntry = $activeQueueEntry ?? $latestQueueEntry;
        $qrToken = $appointment->qrCodeTokens
            ->sortByDesc('created_at')
            ->first();
        $queueState = $this->queueStateFor($appointment);
        $serviceDurationMinutes = max((int) ($appointment->service?->average_service_duration_minutes ?? 30), 1);
        $timeSlot = $appointment->appointment_time
            ? sprintf(
                '%s - %s',
                DashboardFormatting::shortTime($appointment->appointment_time),
                DashboardFormatting::shortTime(
                    $appointment->appointment_date
                        ? $appointment->appointment_date->copy()->setTimeFromTimeString((string) $appointment->appointment_time)->addMinutes($serviceDurationMinutes)
                        : now()->addMinutes($serviceDurationMinutes)
                )
            )
            : '--';
        $visits = (int) ($appointment->customer?->appointments_count ?? 0);
        $noShows = (int) ($appointment->customer?->no_show_appointments_count ?? 0);
        $activeQueuePosition = $activeQueueEntry
            ? $this->activeQueuePosition($activeQueueEntry)
            : null;
        $waitMinutes = $activeQueuePosition !== null
            ? max(($activeQueuePosition - 1) * ($appointment->service?->average_service_duration_minutes ?? 10), 0)
            : null;
        $displayStatusValue = $appointment->appointment_status->value;
        $completedFromQueue = false;
        if (
            $latestQueueEntry
            && $latestQueueEntry->queue_status->value === QueueEntryStatus::Completed->value
        ) {
            $displayStatusValue = QueueEntryStatus::Completed->value;
            $completedFromQueue = true;
        }

        if ($completedFromQueue && $waitMinutes === null) {
            $waitMinutes = 0;
        }

        return [
            'id' => $appointment->getKey(),
            'displayId' => $this->appointmentDisplayId($appointment),
            'branchId' => $appointment->branch_id,
            'serviceId' => $appointment->service_id,
            'customerName' => $appointment->customer?->full_name ?? 'Unknown Customer',
            'initials' => DashboardFormatting::initials($appointment->customer?->full_name ?? 'UC'),
            'avatarUrl' => null,
            'branch' => $appointment->branch?->branch_name ?? 'Main Branch',
            'service' => $appointment->service?->service_name ?? 'General Service',
            'date' => DashboardFormatting::shortDate($appointment->appointment_date),
            'time' => DashboardFormatting::shortTime($appointment->appointment_time),
            'status' => DashboardFormatting::appointmentStatusLabel($displayStatusValue),
            'queueState' => $queueState,
            'email' => $appointment->customer?->email_address ?? 'no-email@smartqdz.local',
            'phone' => $appointment->customer?->phone_number ?? '--',
            'visits' => $visits,
            'noShows' => $noShows,
            'timeSlot' => $timeSlot,
            'staffName' => $appointment->staffMember?->full_name ?? 'Unassigned',
            'staffInitials' => DashboardFormatting::initials($appointment->staffMember?->full_name ?? 'NA'),
            'checkedInAt' => $queueEntry?->checked_in_at ? DashboardFormatting::shortTime($queueEntry->checked_in_at) : null,
            'queuePosition' => $activeQueuePosition !== null
                ? '#'.$activeQueuePosition
                : ($completedFromQueue ? '#1' : null),
            'waitTime' => $waitMinutes !== null ? '~'.$waitMinutes.' min' : null,
            'qrStatus' => match (true) {
                $qrToken?->token_status === TokenStatus::Consumed => 'Used',
                $qrToken?->token_status === TokenStatus::Expired => 'Expired',
                $qrToken?->token_status === TokenStatus::Active => 'Ready',
                default => 'Unavailable',
            },
        ];
    }

    protected function activeQueuePosition(QueueEntry $entry): ?int
    {
        if (! $entry->queue_session_id) {
            return null;
        }

        $activeIds = QueueEntry::query()
            ->where('queue_session_id', $entry->queue_session_id)
            ->whereNotIn('queue_status', [
                QueueEntryStatus::Completed->value,
                QueueEntryStatus::Cancelled->value,
            ])
            ->orderBy('queue_position')
            ->pluck('id')
            ->values();

        $index = $activeIds->search($entry->getKey());

        if (! is_int($index)) {
            return null;
        }

        return $index + 1;
    }
}
