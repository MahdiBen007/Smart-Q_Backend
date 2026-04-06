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
use App\Support\Dashboard\DashboardCatalog;
use App\Support\Dashboard\DashboardFormatting;
use App\Support\Dashboard\OperationalWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QueueMonitorController extends DashboardApiController
{
    public function __construct(
        protected OperationalWorkflowService $workflow,
    ) {}

    public function bootstrap(Request $request)
    {
        $today = now()->toDateString();
        $shouldRestrictToAssignedBranch = $this->shouldRestrictToAssignedBranch($request);
        $entriesQuery = $this->scopeQueryByCompanyRelation(
            QueueEntry::query()
                ->with($this->queueEntryRelations())
                ->whereHas('queueSession', fn ($query) => $query->whereDate('session_date', $today))
                ->orderBy('queue_position'),
            $request,
            'queueSession.branch'
        );
        $entriesQuery = $this->scopeQueryByAssignedBranchRelation($entriesQuery, $request, 'queueSession.branch');
        $entries = $entriesQuery->get();

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
        $services = $servicesQuery->get();

        $firstSessionQuery = $this->scopeQueryByCompanyRelation(
            DailyQueueSession::query()
                ->whereDate('session_date', $today)
                ->orderBy('created_at'),
            $request,
            'branch'
        );
        $firstSessionQuery = $this->scopeQueryByAssignedBranchRelation($firstSessionQuery, $request, 'branch');
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
                ->filter(fn (QueueEntry $entry) => ! in_array($entry->queue_status->value, ['completed', 'cancelled'], true))
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
        $this->ensureCompanyAccess($request, $branch);

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

        return $this->respond(
            $this->transformQueueEntry($updated->loadMissing($this->queueEntryRelations())),
            'Queue entry skipped successfully.'
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
        $session = $this->resolveSession($validated);
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
        $session = $this->resolveSession($request->validated());
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
        $session = $this->resolveSession($request->validated());
        $this->ensureCompanyAccess($request, $session);
        $affectedRows = $this->workflow->clearWaitingEntries($session);
        $this->invalidateDashboardCache($request, $session->branch?->company_id);

        return $this->respond([
            'queueSessionId' => $session->getKey(),
            'affected_rows' => $affectedRows,
        ], 'Waiting tickets cleared successfully.');
    }

    protected function resolveSession(array $validated): DailyQueueSession
    {
        if (! empty($validated['queue_session_id'])) {
            return DailyQueueSession::query()->findOrFail($validated['queue_session_id']);
        }

        return $this->workflow->ensureSession(
            Branch::query()->findOrFail($validated['branch_id']),
            Service::query()->findOrFail($validated['service_id']),
        );
    }

    protected function queueEntryRelations(): array
    {
        return [
            'customer',
            'queueSession.branch',
            'queueSession.service',
            'walkInTicket',
            'walkInTicket.customer',
            'appointment',
            'appointment.customer',
        ];
    }

    protected function transformQueueEntry(QueueEntry $entry): array
    {
        $ticketId = $entry->walkInTicket
            ? 'W-'.$entry->walkInTicket->ticket_number
            : 'A-'.Str::upper(substr($entry->appointment_id ?? $entry->getKey(), 0, 6));
        $estimatedWait = $entry->queue_status->value === 'serving'
            ? 0
            : max(($entry->queue_position - 1) * ($entry->queueSession?->service?->average_service_duration_minutes ?? 10), 0);
        $customerName = $entry->customer?->full_name
            ?? $entry->appointment?->customer?->full_name
            ?? $entry->walkInTicket?->customer?->full_name
            ?? 'Walk-in Customer';

        return [
            'id' => $entry->getKey(),
            'queueSessionId' => $entry->queue_session_id,
            'branchId' => $entry->queueSession?->branch_id,
            'serviceId' => $entry->queueSession?->service_id,
            'appointmentId' => $entry->appointment_id,
            'walkInTicketId' => $entry->ticket_id,
            'ticketId' => $ticketId,
            'queuePosition' => $entry->queue_position,
            'customer' => $customerName,
            'customerType' => 'person',
            'branch' => $entry->queueSession?->branch?->branch_name ?? 'Main Branch',
            'service' => $entry->queueSession?->service?->service_name ?? 'General Service',
            'checkIn' => $entry->checked_in_at ? DashboardFormatting::shortTime($entry->checked_in_at) : '--',
            'startedAt' => $entry->service_started_at ? DashboardFormatting::shortTime($entry->service_started_at) : '--',
            'counter' => null,
            'status' => DashboardFormatting::queueStatusLabel($entry->queue_status->value),
            'eta' => DashboardFormatting::minutesLabel($estimatedWait),
        ];
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
