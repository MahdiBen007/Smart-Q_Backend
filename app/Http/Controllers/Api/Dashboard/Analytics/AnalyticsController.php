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

class AnalyticsController extends DashboardApiController
{
    public function __construct(
        protected DashboardMetricsService $metrics,
    ) {}

    public function bootstrap()
    {
        $payload = $this->rememberPayload('dashboard:analytics:bootstrap', function (): array {
            $branches = Branch::query()
                ->select(['id', 'branch_name'])
                ->withCount(['appointments', 'walkInTickets'])
                ->orderBy('branch_name')
                ->get();
            $services = Service::query()
                ->select(['id', 'service_name'])
                ->withCount(['appointments', 'walkInTickets'])
                ->orderBy('service_name')
                ->get();
            $staff = StaffMember::query()
                ->select(['id', 'full_name'])
                ->withCount([
                    'servedQueueEntries as completed_served_count' => fn ($query) => $query->where('queue_status', 'completed'),
                ])
                ->orderBy('full_name')
                ->get();

            return [
                'ranges' => [
                    ['key' => 'day', 'label' => 'Today'],
                    ['key' => 'week', 'label' => 'Last 7 Days'],
                    ['key' => 'month', 'label' => 'Last 30 Days'],
                ],
                'branches' => ['All Branches', ...$branches->pluck('branch_name')->all()],
                'branchProfiles' => $this->branchProfiles($branches),
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
                    'day' => $this->trafficSeries(today(), today()),
                    'week' => $this->trafficSeries(today()->subDays(6), today()),
                    'month' => $this->trafficSeries(today()->subDays(29), today()),
                ],
                'baseServiceLoad' => $this->serviceLoad($services),
                'baseLoadDistribution' => $this->loadDistribution($branches),
                'staffEfficiency' => $this->staffEfficiency($staff),
                'heatmapDays' => collect(range(6, 0))
                    ->map(fn (int $offset) => today()->subDays($offset)->format('D'))
                    ->values()
                    ->all(),
                'baseHeatmap' => $this->heatmapRows(),
                'serviceFunnel' => $this->serviceFunnel(),
            ];
        }, 20);

        return $this->respond($payload);
    }

    protected function branchProfiles(Collection $branches): array
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

        $profiles['All Branches'] = [
            'factor' => round(($branches->sum(fn (Branch $branch) => $branch->appointments_count + $branch->walk_in_tickets_count) / max(1, $branches->count())), 2),
            'scheduled' => (int) $branches->sum('appointments_count'),
            'walkIns' => (int) $branches->sum('walk_in_tickets_count'),
        ];

        return $profiles;
    }

    protected function trafficSeries(Carbon $start, Carbon $end): array
    {
        $appointmentCounts = $this->metrics->appointmentCountsByDate($start, $end);
        $walkInCounts = $this->metrics->walkInCountsByDate($start, $end);
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

    protected function heatmapRows(): array
    {
        $startDate = today()->subDays(6);
        $endDate = today();
        $days = collect(range(6, 0))
            ->map(fn (int $offset) => today()->subDays($offset)->copy())
            ->values();
        $appointmentCounts = $this->metrics->appointmentCountsByDateAndHour($startDate, $endDate);
        $walkInCounts = $this->metrics->walkInCountsByDateAndHour($startDate, $endDate);

        return collect(range(8, 18))->map(function (int $hour) use ($days) {
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

    protected function serviceFunnel(): array
    {
        $appointments = max(Appointment::query()->count(), 1);
        $checkedIn = QueueEntry::query()->whereNotNull('checked_in_at')->count();
        $serving = QueueEntry::query()->where('queue_status', 'serving')->count();
        $completed = QueueEntry::query()->where('queue_status', 'completed')->count();

        return [
            ['stage' => 'Booked', 'rate' => '100%'],
            ['stage' => 'Checked In', 'rate' => round(($checkedIn / $appointments) * 100).'%'],
            ['stage' => 'Serving', 'rate' => round(($serving / $appointments) * 100).'%'],
            ['stage' => 'Completed', 'rate' => round(($completed / $appointments) * 100).'%'],
        ];
    }
}
