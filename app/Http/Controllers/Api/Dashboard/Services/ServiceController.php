<?php

namespace App\Http\Controllers\Api\Dashboard\Services;

use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\Services\ListServicesRequest;
use App\Http\Requests\Api\Dashboard\Services\StoreServiceRequest;
use App\Http\Requests\Api\Dashboard\Services\UpdateServiceRequest;
use App\Http\Requests\Api\Dashboard\Services\UpdateServiceStatusRequest;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Support\Dashboard\DashboardCatalog;
use App\Support\Dashboard\DashboardFormatting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ServiceController extends DashboardApiController
{
    public function bootstrap(ListServicesRequest $request)
    {
        $today = now()->toDateString();
        $contextBranchId = $this->contextBranchId($request);
        $services = $this->applyFilters($this->baseQuery($request), $request)->get();
        $branchesQuery = $this->scopeQueryByCompanyColumn(
            Branch::query()
                ->select(['id', 'branch_name', 'branch_code', 'branch_status'])
                ->with([
                    'dailyQueueSessions' => fn ($query) => $query
                        ->select(['id', 'branch_id', 'session_date'])
                        ->whereDate('session_date', $today),
                    'dailyQueueSessions.queueEntries' => fn ($query) => $query
                        ->select(['id', 'queue_session_id', 'queue_position', 'queue_status']),
                ])
                ->orderBy('branch_name'),
            $request
        );
        $branchesQuery = $this->scopeQueryByAssignedBranchColumn($branchesQuery, $request, 'id');
        $branches = $branchesQuery->get();
        $branchOptions = $branches->pluck('branch_name')->values()->all();

        if (! $this->shouldRestrictToAssignedBranch($request)) {
            $branchOptions = ['All Branches', ...$branchOptions];
        }

        return $this->respond([
            'branchOptions' => $branchOptions,
            'statusOptions' => ['All Statuses', ...DashboardCatalog::SERVICE_STATUSES],
            'branches' => $branches->map(fn (Branch $branch) => $this->transformServiceBranch($branch))->values()->all(),
            'services' => $services->map(fn (Service $service) => $this->transformService($service, $contextBranchId))->values()->all(),
            'summary' => $this->summary($services),
        ]);
    }

    public function index(ListServicesRequest $request)
    {
        $contextBranchId = $this->contextBranchId($request);

        return $this->respondIndexCollection(
            $request,
            $this->applyFilters($this->baseQuery($request), $request),
            fn (Service $service) => $this->transformService($service, $contextBranchId)
        );
    }

    public function show(ListServicesRequest $request, Service $service)
    {
        $this->ensureCompanyAccess($request, $service);
        $contextBranchId = $this->contextBranchId($request);
        $service->loadMissing($this->serviceRelations($contextBranchId));

        return $this->respond($this->transformService($service, $contextBranchId));
    }

    public function store(StoreServiceRequest $request)
    {
        $validated = $request->validated();
        $service = Service::query()->create($this->servicePayload($validated));

        $service->branches()->sync($validated['branch_ids']);
        $service->counters()->sync($validated['counter_ids'] ?? []);
        $this->invalidateDashboardCache($request, $request->user()?->staffMember?->company_id);

        $contextBranchId = $this->contextBranchId($request);

        return $this->respond(
            $this->transformService($service->fresh($this->serviceRelations($contextBranchId)), $contextBranchId),
            'Service created successfully.',
            201
        );
    }

    public function update(UpdateServiceRequest $request, Service $service)
    {
        $this->ensureCompanyAccess($request, $service);
        $validated = $request->validated();

        $isAdmin = $this->isDashboardAdmin($request);
        $assignedBranchId = $this->currentBranchId($request);
        $contextBranchId = $this->contextBranchId($request);

        if (! $isAdmin) {
            unset($validated['branch_ids']);
        }

        if ($contextBranchId !== null) {
            if ($isAdmin && array_key_exists('service_code', $validated)) {
                $service->update(['service_code' => $validated['service_code']]);
            }

            $overridePayload = $this->branchServiceOverridePayload($validated);

            if ($overridePayload !== []) {
                if (! $service->branches()->whereKey($contextBranchId)->exists()) {
                    $service->branches()->attach($contextBranchId);
                }

                $service->branches()->updateExistingPivot($contextBranchId, $overridePayload);
            }
        } elseif ($isAdmin) {
            $service->update($this->servicePayload($validated, $service));
        }

        if ($isAdmin && $contextBranchId === null && isset($validated['branch_ids'])) {
            $service->branches()->sync($validated['branch_ids']);
        }

        if (array_key_exists('counter_ids', $validated)) {
            if ($contextBranchId !== null) {
                $requestedCounterIds = $validated['counter_ids'] ?? [];
                $allowedCounterIds = Counter::query()
                    ->whereIn('id', $requestedCounterIds)
                    ->where('branch_id', $contextBranchId)
                    ->pluck('id')
                    ->values()
                    ->all();

                abort_unless(count($allowedCounterIds) === count($requestedCounterIds), 403);

                $otherBranchCounterIds = $service->counters()
                    ->where('branch_id', '!=', $contextBranchId)
                    ->pluck('counters.id')
                    ->values()
                    ->all();

                $service->counters()->sync(array_values(array_unique([...$otherBranchCounterIds, ...$allowedCounterIds])));
            } elseif ($isAdmin || $assignedBranchId === null) {
                $service->counters()->sync($validated['counter_ids'] ?? []);
            } else {
                $requestedCounterIds = $validated['counter_ids'] ?? [];
                $allowedCounterIds = Counter::query()
                    ->whereIn('id', $requestedCounterIds)
                    ->where('branch_id', $assignedBranchId)
                    ->pluck('id')
                    ->values()
                    ->all();

                abort_unless(count($allowedCounterIds) === count($requestedCounterIds), 403);

                $otherBranchCounterIds = $service->counters()
                    ->where('branch_id', '!=', $assignedBranchId)
                    ->pluck('counters.id')
                    ->values()
                    ->all();

                $service->counters()->sync(array_values(array_unique([...$otherBranchCounterIds, ...$allowedCounterIds])));
            }
        }

        $this->invalidateDashboardCache($request, $service->branch?->company_id);

        return $this->respond(
            $this->transformService($service->fresh($this->serviceRelations($contextBranchId)), $contextBranchId),
            'Service updated successfully.'
        );
    }

    public function updateStatus(UpdateServiceStatusRequest $request, Service $service)
    {
        $this->ensureCompanyAccess($request, $service);
        $contextBranchId = $this->contextBranchId($request);
        $isAdmin = $this->isDashboardAdmin($request);
        $status = $request->validated('status') === 'Active';

        if ($contextBranchId !== null) {
            if (! $service->branches()->whereKey($contextBranchId)->exists()) {
                $service->branches()->attach($contextBranchId);
            }

            $service->branches()->updateExistingPivot($contextBranchId, [
                'is_active_override' => $status,
            ]);
        } elseif ($isAdmin) {
            $service->update(['is_active' => $status]);
        }
        $this->invalidateDashboardCache($request, $service->branch?->company_id);

        return $this->respond(
            $this->transformService($service->fresh($this->serviceRelations($contextBranchId)), $contextBranchId),
            'Service status updated successfully.'
        );
    }

    protected function baseQuery(ListServicesRequest $request): Builder
    {
        $contextBranchId = $this->contextBranchId($request);
        $query = $this->scopeQueryByCompanyRelation(
            Service::query()->with($this->serviceRelations($contextBranchId))->orderBy('service_name'),
            $request,
            'branches'
        );

        if ($this->shouldRestrictToAssignedBranch($request)) {
            $branchId = $this->currentBranchId($request);

            if ($branchId !== null) {
                $query->where(function (Builder $serviceQuery) use ($branchId): void {
                    $serviceQuery
                        ->where('branch_id', $branchId)
                        ->orWhereHas('branches', fn (Builder $branchQuery) => $branchQuery->whereKey($branchId));
                });
            }
        }

        return $query;
    }

    protected function serviceRelations(?string $branchId = null): array
    {
        $today = now()->toDateString();

        return [
            'branches' => fn ($query) => $query
                ->select(['branches.id', 'branch_name'])
                ->when($branchId !== null, fn ($branchQuery) => $branchQuery->whereKey($branchId)),
            'counters' => fn ($query) => $query
                ->select(['counters.id', 'branch_id', 'counter_code', 'counter_name', 'is_active'])
                ->when($branchId !== null, fn ($counterQuery) => $counterQuery->where('branch_id', $branchId)),
            'walkInTickets' => fn ($query) => $query
                ->select(['id', 'service_id', 'created_at'])
                ->whereDate('created_at', $today)
                ->when($branchId !== null, fn ($ticketQuery) => $ticketQuery->where('branch_id', $branchId)),
            'dailyQueueSessions' => fn ($query) => $query
                ->select(['id', 'service_id', 'session_date'])
                ->whereDate('session_date', $today)
                ->when($branchId !== null, fn ($sessionQuery) => $sessionQuery->where('branch_id', $branchId)),
            'dailyQueueSessions.queueEntries' => fn ($query) => $query
                ->select(['id', 'queue_session_id', 'queue_position', 'queue_status']),
        ];
    }

    protected function servicePayload(array $validated, ?Service $service = null): array
    {
        return [
            'branch_id' => $validated['branch_ids'][0] ?? $service?->branch_id,
            'service_name' => $validated['name'] ?? $service?->service_name,
            'average_service_duration_minutes' => $validated['average_service_duration_minutes'] ?? $service?->average_service_duration_minutes,
            'is_active' => array_key_exists('status', $validated) ? $validated['status'] === 'Active' : $service?->is_active,
            'service_code' => $validated['service_code'] ?? $service?->service_code,
            'service_subtitle' => array_key_exists('subtitle', $validated) ? $validated['subtitle'] : $service?->service_subtitle,
            'service_description' => array_key_exists('description', $validated) ? $validated['description'] : $service?->service_description,
            'service_icon' => array_key_exists('icon', $validated) ? $validated['icon'] : ($service?->service_icon ?? 'support'),
        ];
    }

    protected function applyFilters(Builder $query, ListServicesRequest $request): Builder
    {
        $search = trim((string) $request->input('search', ''));
        $status = $request->input('status');
        $branchId = $request->input('branch_id');

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('service_name', 'like', '%'.$search.'%')
                    ->orWhere('service_code', 'like', '%'.$search.'%')
                    ->orWhere('service_subtitle', 'like', '%'.$search.'%')
                    ->orWhere('service_description', 'like', '%'.$search.'%');
            });
        }

        if (is_string($status) && $status !== '') {
            $query->where('is_active', $status === 'Active');
        }

        if (is_string($branchId) && $branchId !== '') {
            $query->whereHas('branches', fn (Builder $branchQuery) => $branchQuery->whereKey($branchId));
        }

        return $query;
    }

    protected function contextBranchId(Request $request): ?string
    {
        if ($this->shouldRestrictToAssignedBranch($request)) {
            return $this->currentBranchId($request);
        }

        if (! $this->isDashboardAdmin($request)) {
            return null;
        }

        $branchId = trim((string) $request->input('branch_id', ''));

        return $branchId !== '' ? $branchId : null;
    }

    protected function branchServiceOverridePayload(array $validated): array
    {
        $payload = [];

        if (array_key_exists('name', $validated)) {
            $payload['service_name_override'] = $validated['name'];
        }

        if (array_key_exists('subtitle', $validated)) {
            $payload['service_subtitle_override'] = $validated['subtitle'];
        }

        if (array_key_exists('description', $validated)) {
            $payload['service_description_override'] = $validated['description'];
        }

        if (array_key_exists('icon', $validated)) {
            $payload['service_icon_override'] = $validated['icon'];
        }

        if (array_key_exists('average_service_duration_minutes', $validated)) {
            $payload['average_service_duration_minutes_override'] = $validated['average_service_duration_minutes'];
        }

        if (array_key_exists('status', $validated)) {
            $payload['is_active_override'] = $validated['status'] === 'Active';
        }

        return $payload;
    }

    protected function serviceConfigForBranch(Service $service, ?string $branchId = null): array
    {
        $pivot = null;

        if ($branchId !== null) {
            $pivot = $service->branches->firstWhere('id', $branchId)?->pivot;
        }

        $avgDurationMinutes = $pivot?->average_service_duration_minutes_override ?? $service->average_service_duration_minutes;
        $avgDurationMinutes = is_numeric($avgDurationMinutes) ? (int) $avgDurationMinutes : 0;

        $isActive = $pivot?->is_active_override ?? $service->is_active;
        $isActive = $isActive === null ? true : (bool) $isActive;

        return [
            'name' => $pivot?->service_name_override ?? $service->service_name,
            'subtitle' => $pivot?->service_subtitle_override ?? ($service->service_subtitle ?: null),
            'description' => $pivot?->service_description_override ?? ($service->service_description ?: null),
            'icon' => $pivot?->service_icon_override ?? ($service->service_icon ?: null),
            'avgDurationMinutes' => $avgDurationMinutes,
            'isActive' => $isActive,
        ];
    }

    protected function transformService(Service $service, ?string $contextBranchId = null): array
    {
        $serviceConfig = $this->serviceConfigForBranch($service, $contextBranchId);
        $avgDurationMinutes = $serviceConfig['avgDurationMinutes'];
        $isActive = $serviceConfig['isActive'];

        $activeEntries = $service->dailyQueueSessions
            ->filter(fn ($session) => optional($session->session_date)?->isToday())
            ->flatMap->queueEntries
            ->filter(fn (QueueEntry $entry) => ! in_array($entry->queue_status->value, ['completed', 'cancelled'], true))
            ->values();

        $servedToday = $service->dailyQueueSessions
            ->filter(fn ($session) => optional($session->session_date)?->isToday())
            ->flatMap->queueEntries
            ->filter(fn (QueueEntry $entry) => $entry->queue_status->value === 'completed')
            ->count();

        $todayTickets = $service->walkInTickets
            ->filter(fn ($ticket) => $ticket->created_at?->isToday())
            ->count();

        $waitMinutes = $activeEntries->count() > 0
            ? (int) round(($activeEntries->avg('queue_position') ?: 1) * max($avgDurationMinutes, 1))
            : 0;

        return [
            'id' => $service->getKey(),
            'serviceCode' => $service->service_code,
            'name' => $serviceConfig['name'],
            'subtitle' => $serviceConfig['subtitle'] ?: 'Operational service',
            'description' => $serviceConfig['description'] ?: 'Service configuration available from the dashboard API.',
            'icon' => $serviceConfig['icon'] ?: 'support',
            'avgDurationMinutes' => $avgDurationMinutes,
            'status' => $isActive ? 'Active' : 'Inactive',
            'branchIds' => $service->branches->pluck('id')->values()->all(),
            'counterIds' => $service->counters->pluck('id')->values()->all(),
            'todayTickets' => $todayTickets,
            'performance' => [
                'avgWaitTime' => DashboardFormatting::minutesLabel($waitMinutes, '0m'),
                'avgServiceTime' => DashboardFormatting::serviceDurationLabel($avgDurationMinutes, '0m'),
                'servedToday' => $servedToday,
                'servedProgress' => max(5, min(100, $todayTickets > 0 ? (int) round(($servedToday / $todayTickets) * 100) : 25)),
            ],
        ];
    }

    protected function transformServiceBranch(Branch $branch): array
    {
        $activeEntries = $branch->dailyQueueSessions
            ->filter(fn ($session) => optional($session->session_date)?->isToday())
            ->flatMap->queueEntries
            ->filter(fn (QueueEntry $entry) => ! in_array($entry->queue_status->value, ['completed', 'cancelled'], true))
            ->values();

        $waitMinutes = $activeEntries->count() > 0
            ? (int) round(($activeEntries->avg('queue_position') ?: 1) * 5)
            : 0;

        $tone = DashboardFormatting::trafficTone($waitMinutes);

        return [
            'id' => $branch->getKey(),
            'code' => $branch->branch_code ?: 'BR',
            'name' => $branch->branch_name,
            'trafficLabel' => match ($tone) {
                'high' => 'High Traffic',
                'normal' => 'Normal Traffic',
                default => 'Low Traffic',
            },
            'trafficTone' => $tone,
            'isOpen' => $branch->branch_status !== 'maintenance',
        ];
    }

    protected function summary($services): array
    {
        $currentMonthAvg = (float) $services
            ->filter(fn (Service $service) => $service->created_at?->isCurrentMonth())
            ->avg('average_service_duration_minutes');

        $previousMonthAvg = (float) $services
            ->filter(fn (Service $service) => $service->created_at?->copy()->isSameMonth(now()->subMonth()))
            ->avg('average_service_duration_minutes');

        return [
            'addedThisMonth' => $services->filter(fn (Service $service) => $service->created_at?->isCurrentMonth())->count(),
            'avgDurationDeltaMinutes' => (int) round($currentMonthAvg - $previousMonthAvg),
        ];
    }
}
