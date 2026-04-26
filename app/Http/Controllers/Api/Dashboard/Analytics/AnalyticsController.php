<?php

namespace App\Http\Controllers\Api\Dashboard\Analytics;

use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\StaffMember;
use App\Support\Dashboard\DashboardMetricsService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;

class AnalyticsController extends DashboardApiController
{
    public function __construct(
        protected DashboardMetricsService $metrics,
    ) {}

    public function bootstrap(Request $request)
    {
        $companyId = $this->currentCompanyId($request);
        $branchId = $this->shouldRestrictToAssignedBranch($request)
            ? $this->currentBranchId($request)
            : null;
        $serviceId = $this->shouldRestrictToAssignedService($request)
            ? $this->currentServiceId($request)
            : null;
        $cacheKey = sprintf('analytics:bootstrap:branch:%s:service:%s', $branchId ?: 'all', $serviceId ?: 'all');
        $payload = $this->rememberScopedPayload($request, $cacheKey, function () use ($request, $companyId, $branchId, $serviceId): array {
            $branchesQuery = $this->scopeQueryByCompanyColumn(
                Branch::query()
                    ->select(['id', 'branch_name'])
                    ->withCount(['appointments', 'walkInTickets'])
                    ->orderBy('branch_name'),
                $request
            );
            $branches = $this->scopeQueryByAssignedBranchColumn($branchesQuery, $request, 'id')->get();
            $servicesQuery = $this->scopeQueryByCompanyRelation(
                Service::query()
                    ->select(['id', 'service_name'])
                    ->withCount(['appointments', 'walkInTickets'])
                    ->orderBy('service_name'),
                $request,
                'branches'
            );
            $servicesQuery = $this->scopeQueryByAssignedBranchRelation($servicesQuery, $request, 'branches');
            $servicesQuery = $this->scopeQueryByAssignedServiceColumn($servicesQuery, $request, 'id');
            $services = $servicesQuery->get();
            $staffQuery = $this->scopeQueryByCompanyColumn(
                StaffMember::query()
                    ->select(['id', 'full_name'])
                    ->withCount([
                        'servedQueueEntries as completed_served_count' => fn ($query) => $query->where('queue_status', 'completed'),
                    ])
                    ->orderBy('full_name'),
                $request
            );
            $staffQuery = $this->scopeQueryByAssignedBranchColumn($staffQuery, $request);
            $staffQuery = $this->scopeQueryByAssignedServiceColumn($staffQuery, $request);
            $staff = $staffQuery->get();
            $branchOptions = $branches->pluck('branch_name')->values()->all();

            return [
                'ranges' => [
                    ['key' => 'day', 'label' => 'Today'],
                    ['key' => 'week', 'label' => 'Last 7 Days'],
                    ['key' => 'month', 'label' => 'Last 30 Days'],
                ],
                'branches' => $branchId === null
                    ? ['All Branches', ...$branchOptions]
                    : $branchOptions,
                'branchProfiles' => $this->branchProfiles($branches, $branchId === null),
                'baseFactorByRange' => [
                    'day' => 1,
                    'week' => 1.15,
                    'month' => 1.3,
                ],
                'baselineDaysByRange' => [
                    'day' => 1,
                    'week' => 7,
                    'month' => 30,
                ],
                'trafficByRange' => [
                    'day' => $this->trafficSeries(today(), today(), $companyId, $branchId, $serviceId),
                    'week' => $this->trafficSeries(today()->subDays(6), today(), $companyId, $branchId, $serviceId),
                    'month' => $this->trafficSeries(today()->subDays(29), today(), $companyId, $branchId, $serviceId),
                ],
                'baseServiceLoad' => $this->serviceLoad($services),
                'baseLoadDistribution' => $this->loadDistribution($branches),
                'staffEfficiency' => $this->staffEfficiency($staff),
                'heatmapDays' => collect(range(6, 0))
                    ->map(fn (int $offset) => today()->subDays($offset)->format('D'))
                    ->values()
                    ->all(),
                'baseHeatmap' => $this->heatmapRows($companyId, $branchId, $serviceId),
                'serviceFunnel' => $this->serviceFunnel($companyId, $branchId, $serviceId),
            ];
        }, 20);

        return $this->respond($payload);
    }

    protected function branchProfiles(Collection $branches, bool $includeAllBranches = true): array
    {
        $profiles = [];

        foreach ($branches as $branch) {
            $scheduled = (int) $branch->appointments_count;
            $walkIns = (int) $branch->walk_in_tickets_count;
            $profiles[$branch->branch_name] = [
                'factor' => round(($scheduled + $walkIns) / max(1, $branches->count()), 2),
                'scheduled' => $scheduled,
                'walkIns' => $walkIns,
            ];
        }

        if ($includeAllBranches) {
            $profiles['All Branches'] = [
                'factor' => round(($branches->sum(fn (Branch $branch) => $branch->appointments_count + $branch->walk_in_tickets_count) / max(1, $branches->count())), 2),
                'scheduled' => (int) $branches->sum('appointments_count'),
                'walkIns' => (int) $branches->sum('walk_in_tickets_count'),
            ];
        }

        return $profiles;
    }

    protected function trafficSeries(
        Carbon $start,
        Carbon $end,
        ?string $companyId = null,
        ?string $branchId = null,
        ?string $serviceId = null,
    ): array
    {
        $appointmentCounts = $this->metrics->appointmentCountsByDate($start, $end, $companyId, $branchId, $serviceId);
        $walkInCounts = $this->metrics->walkInCountsByDate($start, $end, $companyId, $branchId, $serviceId);
        $items = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $dateKey = $this->metrics->formatDateKey($cursor);
            $items[] = [
                'label' => $cursor->format('M d'),
                'appointments' => $appointmentCounts[$dateKey] ?? 0,
                'walkIns' => $walkInCounts[$dateKey] ?? 0,
            ];

            $cursor->addDay();
        }

        return $items;
    }

    protected function heatmapRows(
        ?string $companyId = null,
        ?string $branchId = null,
        ?string $serviceId = null,
    ): array
    {
        $startDate = today()->subDays(6);
        $endDate = today();
        $days = collect(range(6, 0))
            ->map(fn (int $offset) => today()->subDays($offset)->copy())
            ->values();
        $appointmentCounts = $this->metrics->appointmentCountsByDateAndHour($startDate, $endDate, $companyId, $branchId, $serviceId);
        $walkInCounts = $this->metrics->walkInCountsByDateAndHour($startDate, $endDate, $companyId, $branchId, $serviceId);

        return collect(range(8, 18))->map(function (int $hour) use ($days, $appointmentCounts, $walkInCounts) {
            return [
                'hour' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00',
                'values' => $days->map(function (Carbon $day) use ($hour, $appointmentCounts, $walkInCounts) {
                    $key = $day->toDateString().'|'.str_pad((string) $hour, 2, '0', STR_PAD_LEFT);

                    return ($appointmentCounts[$key] ?? 0) + ($walkInCounts[$key] ?? 0);
                })->all(),
            ];
        })->all();
    }

    protected function serviceLoad(Collection $services): array
    {
        $maxCount = max(
            1,
            (int) $services->max(fn (Service $service) => $service->appointments_count + $service->walk_in_tickets_count)
        );

        return $services->map(function (Service $service) use ($maxCount) {
            $count = (int) $service->appointments_count + (int) $service->walk_in_tickets_count;

            return [
                'name' => $service->service_name,
                'count' => $count,
                'progress' => (int) round(($count / $maxCount) * 100),
            ];
        })->values()->all();
    }

    protected function loadDistribution(Collection $branches): array
    {
        $total = max(
            1,
            (int) $branches->sum(fn (Branch $branch) => $branch->appointments_count + $branch->walk_in_tickets_count)
        );
        $colors = ['#2563eb', '#0891b2', '#7c3aed', '#ea580c', '#16a34a', '#db2777'];

        return $branches->values()->map(function (Branch $branch, int $index) use ($total, $colors) {
            $count = (int) $branch->appointments_count + (int) $branch->walk_in_tickets_count;

            return [
                'name' => $branch->branch_name,
                'value' => (int) round(($count / $total) * 100),
                'color' => $colors[$index % count($colors)],
            ];
        })->all();
    }

    protected function staffEfficiency(Collection $staff): array
    {
        return $staff->map(function (StaffMember $member) {
            $score = min(99, max(50, 55 + ((int) $member->completed_served_count * 4)));

            return [
                'initials' => substr($member->full_name, 0, 2),
                'name' => $member->full_name,
                'score' => $score,
                'tone' => $score >= 80 ? 'excellent' : ($score >= 65 ? 'solid' : 'attention'),
            ];
        })->sortByDesc('score')->take(6)->values()->all();
    }

    protected function serviceFunnel(
        ?string $companyId = null,
        ?string $branchId = null,
        ?string $serviceId = null,
    ): array
    {
        $appointmentsQuery = Appointment::query();
        $queueEntriesQuery = QueueEntry::query();

        if ($serviceId !== null) {
            $appointmentsQuery->where('service_id', $serviceId);
            $queueEntriesQuery->whereHas('queueSession', fn ($queueSessionQuery) => $queueSessionQuery->where('service_id', $serviceId));
        }

        if ($branchId !== null) {
            $appointmentsQuery->where('branch_id', $branchId);
            $queueEntriesQuery->whereHas('queueSession', fn ($queueSessionQuery) => $queueSessionQuery->where('branch_id', $branchId));
        } elseif ($companyId !== null) {
            $appointmentsQuery->whereHas('branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId));
            $queueEntriesQuery->whereHas('queueSession.branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId));
        }

        $appointments = max($appointmentsQuery->count(), 1);
        $checkedIn = (clone $queueEntriesQuery)->whereNotNull('checked_in_at')->count();
        $serving = (clone $queueEntriesQuery)->where('queue_status', 'serving')->count();
        $completed = (clone $queueEntriesQuery)->where('queue_status', 'completed')->count();

        return [
            ['stage' => 'Booked', 'rate' => '100%'],
            ['stage' => 'Checked In', 'rate' => round(($checkedIn / $appointments) * 100).'%'],
            ['stage' => 'Serving', 'rate' => round(($serving / $appointments) * 100).'%'],
            ['stage' => 'Completed', 'rate' => round(($completed / $appointments) * 100).'%'],
        ];
    }
}
