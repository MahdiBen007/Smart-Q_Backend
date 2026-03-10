<?php

namespace App\Http\Controllers\Api\Dashboard\Services;

use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\Services\StoreServiceRequest;
use App\Http\Requests\Api\Dashboard\Services\UpdateServiceRequest;
use App\Http\Requests\Api\Dashboard\Services\UpdateServiceStatusRequest;
use App\Models\Branch;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Support\Dashboard\DashboardCatalog;
use App\Support\Dashboard\DashboardFormatting;

class ServiceController extends DashboardApiController
{
    public function bootstrap()
    {
        $services = $this->baseQuery()->get();
        $branches = Branch::query()->with(['dailyQueueSessions.queueEntries'])->orderBy('branch_name')->get();

        return $this->respond([
            'branchOptions' => ['All Branches', ...$branches->pluck('branch_name')->all()],
            'statusOptions' => ['All Statuses', ...DashboardCatalog::SERVICE_STATUSES],
            'branches' => $branches->map(fn (Branch $branch) => $this->transformServiceBranch($branch))->values()->all(),
            'services' => $services->map(fn (Service $service) => $this->transformService($service))->values()->all(),
            'summary' => $this->summary($services),
        ]);
    }

    public function index()
    {
        return $this->respond(
            $this->baseQuery()
                ->get()
                ->map(fn (Service $service) => $this->transformService($service))
                ->values()
                ->all()
        );
    }

    public function show(Service $service)
    {
        $service->loadMissing($this->serviceRelations());

        return $this->respond($this->transformService($service));
    }

    public function store(StoreServiceRequest $request)
    {
        $validated = $request->validated();
        $service = Service::query()->create($this->servicePayload($validated));

        $service->branches()->sync($validated['branch_ids']);

        return $this->respond(
            $this->transformService($service->fresh($this->serviceRelations())),
            'Service created successfully.',
            201
        );
    }

    public function update(UpdateServiceRequest $request, Service $service)
    {
        $validated = $request->validated();
        $service->update($this->servicePayload($validated, $service));

        if (isset($validated['branch_ids'])) {
            $service->branches()->sync($validated['branch_ids']);
        }

        return $this->respond(
            $this->transformService($service->fresh($this->serviceRelations())),
            'Service updated successfully.'
        );
    }

    public function updateStatus(UpdateServiceStatusRequest $request, Service $service)
    {
        $service->update([
            'is_active' => $request->validated('status') === 'Active',
        ]);

        return $this->respond(
            $this->transformService($service->fresh($this->serviceRelations())),
            'Service status updated successfully.'
        );
    }

    protected function baseQuery()
    {
        return Service::query()->with($this->serviceRelations())->orderBy('service_name');
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
