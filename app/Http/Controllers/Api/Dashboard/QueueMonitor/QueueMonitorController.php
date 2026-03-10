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
use Illuminate\Support\Str;

class QueueMonitorController extends DashboardApiController
{
    public function __construct(
        protected OperationalWorkflowService $workflow,
    ) {}

    public function bootstrap()
    {
        $entries = QueueEntry::query()
            ->with($this->queueEntryRelations())
            ->whereHas('queueSession', fn ($query) => $query->whereDate('session_date', now()->toDateString()))
            ->orderBy('queue_position')
            ->get();

        $branches = Branch::query()->orderBy('branch_name')->pluck('branch_name')->all();
        $services = Service::query()->orderBy('service_name')->pluck('service_name')->all();
        $firstSession = DailyQueueSession::query()->whereDate('session_date', now()->toDateString())->orderBy('created_at')->first();

        return $this->respond([
            'queueEntries' => $entries
                ->filter(fn (QueueEntry $entry) => ! in_array($entry->queue_status->value, ['completed', 'cancelled'], true))
                ->map(fn (QueueEntry $entry) => $this->transformQueueEntry($entry))
                ->values()
                ->all(),
            'branchOptions' => ['All Branches', ...$branches],
            'serviceOptions' => ['All Services', ...$services],
            'operationStatusOptions' => DashboardCatalog::QUEUE_MONITOR_STATUSES,
            'filterOptions' => DashboardCatalog::QUEUE_MONITOR_FILTERS,
            'defaults' => [
                'selectedBranch' => 'All Branches',
                'selectedService' => 'All Services',
                'operationStatus' => $firstSession ? $this->queueMonitorStatusFromSession($firstSession->session_status->value) : 'Active',
                'queueFilter' => 'All',
            ],
            'newTicketCounterStart' => (int) \App\Models\WalkInTicket::query()->max('ticket_number'),
        ]);
    }

    public function store(StoreQueueEntryRequest $request)
    {
        $created = $this->workflow->registerWalkIn([
            ...$request->validated(),
            'ticket_source' => 'staff_assisted',
        ]);

        $entry = $created['queue_entry']->loadMissing($this->queueEntryRelations());

        return $this->respond(
            $this->transformQueueEntry($entry),
            'Queue entry created successfully.',
            201
        );
    }

    public function call(QueueEntry $entry)
    {
        $updated = $this->workflow->callEntry(
            $entry->loadMissing($this->queueEntryRelations())
        );

        return $this->respond(
            $this->transformQueueEntry($updated->loadMissing($this->queueEntryRelations())),
            'Queue entry moved to serving.'
        );
    }

    public function skip(QueueEntry $entry)
    {
        $updated = $this->workflow->skipEntry(
            $entry->loadMissing($this->queueEntryRelations())
        );

        return $this->respond(
            $this->transformQueueEntry($updated->loadMissing($this->queueEntryRelations())),
            'Queue entry skipped successfully.'
        );
    }

    public function complete(QueueEntry $entry)
    {
        $updated = $this->workflow->completeEntry(
            $entry->loadMissing($this->queueEntryRelations())
        );

        return $this->respond(
            $this->transformQueueEntry($updated->loadMissing($this->queueEntryRelations())),
            'Queue entry completed successfully.'
        );
    }

    public function updateSessionStatus(UpdateQueueSessionStatusRequest $request)
    {
        $validated = $request->validated();
        $session = $this->resolveSession($validated);
        $updated = $this->workflow->updateSessionStatus($session, $this->sessionStatusFromQueueMonitor($validated['status']));

        return $this->respond([
            'queueSessionId' => $updated->getKey(),
            'status' => $validated['status'],
        ], 'Queue session status updated successfully.');
    }

    public function reset(ResolveQueueSessionRequest $request)
    {
        $session = $this->resolveSession($request->validated());
        $affectedRows = $this->workflow->resetSession($session);

        return $this->respond([
            'queueSessionId' => $session->getKey(),
            'affected_rows' => $affectedRows,
        ], 'Queue session reset successfully.');
    }

    public function clearWaiting(ResolveQueueSessionRequest $request)
    {
        $session = $this->resolveSession($request->validated());
        $affectedRows = $this->workflow->clearWaitingEntries($session);

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
        return ['customer', 'queueSession.branch', 'queueSession.service', 'walkInTicket', 'appointment'];
    }

    protected function transformQueueEntry(QueueEntry $entry): array
    {
        $ticketId = $entry->walkInTicket
            ? 'W-'.$entry->walkInTicket->ticket_number
            : 'A-'.Str::upper(substr($entry->appointment_id ?? $entry->getKey(), 0, 6));
        $estimatedWait = $entry->queue_status->value === 'serving'
            ? 0
            : max(($entry->queue_position - 1) * ($entry->queueSession?->service?->average_service_duration_minutes ?? 10), 0);

        return [
            'ticketId' => $ticketId,
            'customer' => $entry->customer?->full_name ?? 'Walk-in Customer',
            'customerType' => 'person',
            'branch' => $entry->queueSession?->branch?->branch_name ?? 'Main Branch',
            'service' => $entry->queueSession?->service?->service_name ?? 'General Service',
            'checkIn' => $entry->checked_in_at ? DashboardFormatting::shortTime($entry->checked_in_at) : '--',
            'startedAt' => $entry->service_started_at ? DashboardFormatting::shortTime($entry->service_started_at) : '--',
            'counter' => $entry->service_started_at ? DashboardFormatting::serviceCounterLabel($entry->queue_position) : 'Pending',
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
