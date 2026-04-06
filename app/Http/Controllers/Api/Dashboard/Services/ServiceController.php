<?php

namespace App\Http\Controllers\Api\Dashboard\Services;

use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\Services\ListServicesRequest;
use App\Http\Requests\Api\Dashboard\Services\StoreServiceRequest;
use App\Http\Requests\Api\Dashboard\Services\UpdateServiceRequest;
use App\Http\Requests\Api\Dashboard\Services\UpdateServiceStatusRequest;
use App\Models\Branch;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Support\Dashboard\DashboardCatalog;
use App\Support\Dashboard\DashboardFormatting;
use Illuminate\Database\Eloquent\Builder;

class ServiceController extends DashboardApiController
{
    public function bootstrap(ListServicesRequest $request)
    {
        $services = $this->applyFilters($this->baseQuery($request), $request)->get();
        $branches = $this->scopeQueryByCompanyColumn(
            Branch::query()->with(['dailyQueueSessions.queueEntries'])->orderBy('branch_name'),
            $request
        )->get();

        return $this->respond([
            'branchOptions' => ['All Branches', ...$branches->pluck('branch_name')->all()],
            'statusOptions' => ['All Statuses', ...DashboardCatalog::SERVICE_STATUSES],
            'branches' => $branches->map(fn (Branch $branch) => $this->transformServiceBranch($branch))->values()->all(),
            'services' => $services->map(fn (Service $service) => $this->transformService($service))->values()->all(),
            'summary' => $this->summary($services),
        ]);
    }

    public function index(ListServicesRequest $request)
    {
        return $this->respondIndexCollection(
            $request,
            $this->applyFilters($this->baseQuery($request), $request),
            fn (Service $service) => $this->transformService($service)
        );
    }

    public function show(ListServicesRequest $request, Service $service)
    {
        $this->ensureCompanyAccess($request, $service);
        $service->loadMissing($this->serviceRelations());

        return $this->respond($this->transformService($service));
    }

    public function store(StoreServiceRequest $request)
    {
        $validated = $request->validated();
        $service = Service::query()->create($this->servicePayload($validated));

        $service->branches()->sync($validated['branch_ids']);
        $this->invalidateDashboardCache($request, $request->user()?->staffMember?->company_id);

        return $this->respond(
            $this->transformService($service->fresh($this->serviceRelations())),
            'Service created successfully.',
            201
        );
    }

    public function update(UpdateServiceRequest $request, Service $service)
    {
        $this->ensureCompanyAccess($request, $service);
        $validated = $request->validated();
        $service->update($this->servicePayload($validated, $service));

        if (isset($validated['branch_ids'])) {
            $service->branches()->sync($validated['branch_ids']);
        }

        $this->invalidateDashboardCache($request, $service->branch?->company_id);

        return $this->respond(
            $this->transformService($service->fresh($this->serviceRelations())),
            'Service updated successfully.'
        );
    }

    public function updateStatus(UpdateServiceStatusRequest $request, Service $service)
    {
        $this->ensureCompanyAccess($request, $service);
        $service->update([
            'is_active' => $request->validated('status') === 'Active',
        ]);
        $this->invalidateDashboardCache($request, $service->branch?->company_id);

        return $this->respond(
            $this->transformService($service->fresh($this->serviceRelations())),
            'Service status updated successfully.'
        );
    }

    protected function baseQuery(ListServicesRequest $request): Builder
    {
        return $this->scopeQueryByCompanyRelation(
            Service::query()->with($this->serviceRelations())->orderBy('service_name'),
            $request,
            'branches'
        );
    }

    protected function serviceRelations(): array
    {
        return [
            'branches.dailyQueueSessions.queueEntries',
            'walkInTickets',
            'appointments',
            'dailyQueueSessions.queueEntries',
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

    protected function transformService(Service $service): array
    {
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
            ? (int) round(($activeEntries->avg('queue_position') ?: 1) * max($service->average_service_duration_minutes, 1))
            : 0;

        return [
            'id' => $service->getKey(),
            'serviceCode' => $service->service_code,
            'name' => $service->service_name,
            'subtitle' => $service->service_subtitle ?: 'Operational service',
            'description' => $service->service_description ?: 'Service configuration available from the dashboard API.',
            'icon' => $service->service_icon ?: 'support',
            'avgDurationMinutes' => $service->average_service_duration_minutes,
            'status' => $service->is_active ? 'Active' : 'Inactive',
            'branchIds' => $service->branches->pluck('id')->values()->all(),
            'todayTickets' => $todayTickets,
            'performance' => [
                'avgWaitTime' => DashboardFormatting::minutesLabel($waitMinutes, '0m'),
                'avgServiceTime' => DashboardFormatting::serviceDurationLabel($service->average_service_duration_minutes, '0m'),
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
