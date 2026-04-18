<?php

namespace App\Http\Controllers\Api\Dashboard\QueueMonitor;

use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\QueueMonitor\ResolveQueueSessionRequest;
use App\Http\Requests\Api\Dashboard\QueueMonitor\StoreQueueEntryRequest;
use App\Http\Requests\Api\Dashboard\QueueMonitor\UpdateQueueSessionStatusRequest;
use App\Models\Branch;
use App\Models\DailyQueueSession;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Support\Dashboard\BookingCodeFormatter;
use App\Support\Dashboard\DashboardCatalog;
use App\Support\Dashboard\DashboardFormatting;
use App\Support\Dashboard\OperationalWorkflowService;
use Illuminate\Http\Request;

class QueueMonitorController extends DashboardApiController
{
    public function __construct(
        protected OperationalWorkflowService $workflow,
    ) {}

    public function bootstrap(Request $request)
    {
        $this->workflow->runQueueAutomationCycle($this->currentCompanyId($request));

        $today = now()->toDateString();
        $shouldRestrictToAssignedBranch = $this->shouldRestrictToAssignedBranch($request);
        $entriesQuery = $this->scopeQueryByCompanyRelation(
            QueueEntry::query()
                ->with($this->queueEntryRelations())
                ->whereHas('queueSession', fn ($query) => $query->whereDate('session_date', $today))
                ->whereNotIn('queue_status', ['completed', 'cancelled'])
                ->orderBy('queue_position'),
            $request,
            'queueSession.branch'
        );
        $entriesQuery = $this->scopeQueryByAssignedBranchRelation($entriesQuery, $request, 'queueSession.branch');
        $entriesQuery = $this->scopeQueryByAssignedServiceRelation($entriesQuery, $request, 'queueSession', 'service_id');
        $entries = $entriesQuery->lazy(200)
            ->filter(fn (QueueEntry $entry) => $this->shouldExposeInQueueMonitor($entry))
            ->sortBy(function (QueueEntry $entry): array {
                $isCheckedInSpecialNeeds = BookingCodeFormatter::isSpecialNeedsEntry($entry)
                    && $entry->checked_in_at !== null;

                return [
                    $isCheckedInSpecialNeeds ? 0 : 1,
                    (int) $entry->queue_position,
                ];
            })
            ->values();

        $branchesQuery = $this->scopeQueryByCompanyColumn(
            Branch::query()
                ->select(['id', 'branch_name'])
                ->orderBy('branch_name'),
            $request
        );
        $branchesQuery = $this->scopeQueryByAssignedBranchColumn($branchesQuery, $request, 'id');
        $branches = $branchesQuery->get();

        $servicesQuery = $this->scopeQueryByCompanyRelation(
            Service::query()
                ->select(['id', 'service_name'])
                ->orderBy('service_name'),
            $request,
            'branches'
        );
        $servicesQuery = $this->scopeQueryByAssignedBranchRelation($servicesQuery, $request, 'branches');
        $servicesQuery = $this->scopeQueryByAssignedServiceColumn($servicesQuery, $request, 'id');
        $services = $servicesQuery->get();

        $firstSessionQuery = $this->scopeQueryByCompanyRelation(
            DailyQueueSession::query()
                ->whereDate('session_date', $today)
                ->orderBy('created_at'),
            $request,
            'branch'
        );
        $firstSessionQuery = $this->scopeQueryByAssignedBranchRelation($firstSessionQuery, $request, 'branch');
        $firstSessionQuery = $this->scopeQueryByAssignedServiceColumn($firstSessionQuery, $request);
        $firstSession = $firstSessionQuery->first();
        $serviceOptionNames = $services->pluck('service_name')->values()->all();

        $defaultBranchName = $shouldRestrictToAssignedBranch
            ? ($branches->first()?->branch_name ?? 'All Branches')
            : 'All Branches';
        $defaultServiceName = $shouldRestrictToAssignedBranch
            ? ($services->firstWhere('id', $firstSession?->service_id)?->service_name
                ?? ($serviceOptionNames[0] ?? 'All Services'))
            : 'All Services';

        return $this->respond([
            'queueEntries' => $entries
                ->map(fn (QueueEntry $entry) => $this->transformQueueEntry($entry))
                ->values()
                ->all(),
            'branchOptions' => $shouldRestrictToAssignedBranch
                ? $branches->pluck('branch_name')->values()->all()
                : ['All Branches', ...$branches->pluck('branch_name')->all()],
            'serviceOptions' => $shouldRestrictToAssignedBranch
                ? $serviceOptionNames
                : ['All Services', ...$serviceOptionNames],
            'branchRecords' => $branches
                ->map(fn (Branch $branch) => [
                    'id' => $branch->getKey(),
                    'name' => $branch->branch_name,
                ])
                ->values()
                ->all(),
            'serviceRecords' => $services
                ->map(fn (Service $service) => [
                    'id' => $service->getKey(),
                    'name' => $service->service_name,
                ])
                ->values()
                ->all(),
            'operationStatusOptions' => DashboardCatalog::QUEUE_MONITOR_STATUSES,
            'filterOptions' => DashboardCatalog::QUEUE_MONITOR_FILTERS,
            'defaults' => [
                'selectedBranch' => $defaultBranchName,
                'selectedService' => $defaultServiceName,
                'operationStatus' => $firstSession ? $this->queueMonitorStatusFromSession($firstSession->session_status->value) : 'Active',
                'queueFilter' => 'All',
            ],
            'newTicketCounterStart' => (int) \App\Models\WalkInTicket::query()->max('ticket_number'),
        ]);
    }

    public function store(StoreQueueEntryRequest $request)
    {
        $branch = Branch::query()->findOrFail($request->validated('branch_id'));
        $service = Service::query()->findOrFail($request->validated('service_id'));
        $this->ensureCompanyAccess($request, $branch);
        $this->ensureCompanyAccess($request, $service);

        $created = $this->workflow->registerWalkIn([
            ...$request->validated(),
            'ticket_source' => 'staff_assisted',
        ]);

        $entry = $created['queue_entry']->loadMissing($this->queueEntryRelations());
        $this->invalidateDashboardCache($request, $this->currentCompanyId($request));

        return $this->respond(
            $this->transformQueueEntry($entry),
            'Queue entry created successfully.',
            201
        );
    }

    public function call(Request $request, QueueEntry $entry)
    {
        $this->ensureCompanyAccess($request, $entry);
        $updated = $this->workflow->callEntry(
            $entry->loadMissing($this->queueEntryRelations())
        );
        $this->invalidateDashboardCache($request, $entry->queueSession?->branch?->company_id);

        return $this->respond(
            $this->transformQueueEntry($updated->loadMissing($this->queueEntryRelations())),
            'Queue entry moved to serving.'
        );
    }

    public function skip(Request $request, QueueEntry $entry)
    {
        $this->ensureCompanyAccess($request, $entry);
        $updated = $this->workflow->skipEntry(
            $entry->loadMissing($this->queueEntryRelations())
        );
        $this->invalidateDashboardCache($request, $entry->queueSession?->branch?->company_id);
        $message = $updated->walkInTicket
            ? 'Walk-in ticket cancelled successfully.'
            : 'Queue entry skipped successfully.';

        return $this->respond(
            $this->transformQueueEntry($updated->loadMissing($this->queueEntryRelations())),
            $message
        );
    }

    public function complete(Request $request, QueueEntry $entry)
    {
        $this->ensureCompanyAccess($request, $entry);
        $updated = $this->workflow->completeEntry(
            $entry->loadMissing($this->queueEntryRelations())
        );
        $this->invalidateDashboardCache($request, $entry->queueSession?->branch?->company_id);

        return $this->respond(
            $this->transformQueueEntry($updated->loadMissing($this->queueEntryRelations())),
            'Queue entry completed successfully.'
        );
    }

    public function updateSessionStatus(UpdateQueueSessionStatusRequest $request)
    {
        $validated = $request->validated();
        $session = $this->resolveSession($request, $validated);
        $this->ensureCompanyAccess($request, $session);
        $updated = $this->workflow->updateSessionStatus($session, $this->sessionStatusFromQueueMonitor($validated['status']));
        $this->invalidateDashboardCache($request, $session->branch?->company_id);

        return $this->respond([
            'queueSessionId' => $updated->getKey(),
            'status' => $validated['status'],
        ], 'Queue session status updated successfully.');
    }

    public function reset(ResolveQueueSessionRequest $request)
    {
        $session = $this->resolveSession($request, $request->validated());
        $this->ensureCompanyAccess($request, $session);
        $affectedRows = $this->workflow->resetSession($session);
        $this->invalidateDashboardCache($request, $session->branch?->company_id);

        return $this->respond([
            'queueSessionId' => $session->getKey(),
            'affected_rows' => $affectedRows,
        ], 'Queue session reset successfully.');
    }

    public function clearWaiting(ResolveQueueSessionRequest $request)
    {
        $session = $this->resolveSession($request, $request->validated());
        $this->ensureCompanyAccess($request, $session);
        $affectedRows = $this->workflow->clearWaitingEntries($session);
        $this->invalidateDashboardCache($request, $session->branch?->company_id);

        return $this->respond([
            'queueSessionId' => $session->getKey(),
            'affected_rows' => $affectedRows,
        ], 'Waiting tickets cleared successfully.');
    }

    protected function resolveSession(Request $request, array $validated): DailyQueueSession
    {
        if (! empty($validated['queue_session_id'])) {
            return DailyQueueSession::query()->findOrFail($validated['queue_session_id']);
        }

        $branch = Branch::query()->findOrFail($validated['branch_id']);
        $service = Service::query()->findOrFail($validated['service_id']);

        $this->ensureCompanyAccess($request, $branch);
        $this->ensureCompanyAccess($request, $service);

        return $this->workflow->ensureSession($branch, $service);
    }

    protected function queueEntryRelations(): array
    {
        return [
            'customer.user',
            'queueSession.branch.company',
            'queueSession.service',
            'walkInTicket',
            'walkInTicket.customer.user',
            'appointment',
            'appointment.customer.user',
        ];
    }

    protected function transformQueueEntry(QueueEntry $entry): array
    {
        $ticketId = BookingCodeFormatter::queueEntryDisplayCode($entry);
        $isAwaitingCheckIn = $entry->checked_in_at === null
            && in_array($entry->queue_status->value, ['serving', 'next'], true);
        $checkInGraceRemainingSeconds = 0;

        if ($isAwaitingCheckIn) {
            $graceStartedAt = $entry->service_started_at ?? $entry->updated_at ?? $entry->created_at;
            $elapsedSeconds = $graceStartedAt === null
                ? 0
                : max($graceStartedAt->diffInSeconds(now(), false), 0);

            $checkInGraceRemainingSeconds = max(
                OperationalWorkflowService::ABSENT_CHECK_IN_GRACE_SECONDS - $elapsedSeconds,
                0
            );
        }

        $estimatedWait = $entry->queue_status->value === 'serving'
            ? 0
            : max(($entry->queue_position - 1) * ($entry->queueSession?->service?->average_service_duration_minutes ?? 10), 0);
        $customerName = $entry->customer?->full_name
            ?? $entry->appointment?->customer?->full_name
            ?? $entry->walkInTicket?->customer?->full_name
            ?? 'Walk-in Customer';
        $isSpecialNeeds = ($entry->customer?->user?->user_type ?? $entry->appointment?->customer?->user?->user_type ?? $entry->walkInTicket?->customer?->user?->user_type) === BookingCodeFormatter::SPECIAL_NEEDS_TYPE;
        $displayQueuePosition = $isSpecialNeeds ? null : $entry->queue_position;
        $displayEta = $isSpecialNeeds
            ? 'Direct'
            : DashboardFormatting::minutesLabel($estimatedWait);

        return [
            'id' => $entry->getKey(),
            'queueSessionId' => $entry->queue_session_id,
            'branchId' => $entry->queueSession?->branch_id,
            'serviceId' => $entry->queueSession?->service_id,
            'appointmentId' => $entry->appointment_id,
            'walkInTicketId' => $entry->ticket_id,
            'ticketId' => $ticketId,
            'queuePosition' => $displayQueuePosition,
            'customer' => $customerName,
            'customerType' => $isSpecialNeeds ? 'special_needs' : 'person',
            'branch' => $entry->queueSession?->branch?->branch_name ?? 'Main Branch',
            'service' => $entry->queueSession?->service?->service_name ?? 'General Service',
            'checkIn' => $entry->checked_in_at
                ? DashboardFormatting::shortTime($entry->checked_in_at)
                : ($entry->appointment ? 'Not Arrived' : '--'),
            'startedAt' => $entry->service_started_at ? DashboardFormatting::shortTime($entry->service_started_at) : '--',
            'counter' => null,
            'status' => DashboardFormatting::queueStatusLabel($entry->queue_status->value),
            'eta' => $displayEta,
            'awaitingCheckIn' => $isAwaitingCheckIn,
            'checkInGraceRemainingSeconds' => $checkInGraceRemainingSeconds,
        ];
    }

    protected function shouldExposeInQueueMonitor(QueueEntry $entry): bool
    {
        if (! BookingCodeFormatter::isSpecialNeedsEntry($entry)) {
            return true;
        }

        return $entry->checked_in_at !== null;
    }

    protected function sessionStatusFromQueueMonitor(string $status): string
    {
        return match ($status) {
            'Paused' => 'paused',
            'Maintenance' => 'closing_soon',
            default => 'live',
        };
    }

    protected function queueMonitorStatusFromSession(string $status): string
    {
        return match ($status) {
            'paused' => 'Paused',
            'closing_soon' => 'Maintenance',
            default => 'Active',
        };
    }
}
