<?php

namespace App\Http\Controllers\Api\Dashboard\Dashboard;

use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\QueueEntry;
use App\Models\StaffMember;
use App\Models\WalkInTicket;
use App\Support\Dashboard\DashboardFormatting;
use App\Support\Dashboard\DashboardMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends DashboardApiController
{
    public function __construct(
        protected DashboardMetricsService $metrics,
    ) {}

    public function kpis()
    {
        $payload = $this->rememberPayload('dashboard:dashboard:kpis', function (): array {
            $todayAppointments = Appointment::query()->whereDate('appointment_date', today())->count();
            $yesterdayAppointments = Appointment::query()->whereDate('appointment_date', today()->copy()->subDay())->count();
            $todayWalkIns = WalkInTicket::query()->whereDate('created_at', today())->count();
            $yesterdayWalkIns = WalkInTicket::query()->whereDate('created_at', today()->copy()->subDay())->count();
            $activeBranches = Branch::query()->where('branch_status', 'active')->count();
            $totalBranches = max(Branch::query()->count(), 1);
            $avgWaitToday = $this->metrics->averageWaitMinutes(today());
            $avgWaitYesterday = $this->metrics->averageWaitMinutes(today()->copy()->subDay());

            return [
                $this->buildKpi('Appointments Today', $todayAppointments, $yesterdayAppointments, 'CalendarDays', 'Today', 'info', 'primary'),
                $this->buildKpi('Walk-ins Today', $todayWalkIns, $yesterdayWalkIns, 'Footprints', 'Live', 'success', 'success'),
                [
                    'title' => 'Active Branches',
                    'value' => $activeBranches,
                    'change' => $activeBranches.'/'.$totalBranches,
                    'changePositive' => true,
                    'iconKey' => 'Building2',
                    'progress' => (int) round(($activeBranches / $totalBranches) * 100),
                    'tag' => 'Network',
                    'tagType' => 'info',
                    'color' => 'info',
                ],
                [
                    'title' => 'Avg Wait Time',
                    'value' => $avgWaitToday,
                    'change' => $this->changeLabel($avgWaitToday, $avgWaitYesterday, inverse: true),
                    'changePositive' => $avgWaitToday <= $avgWaitYesterday,
                    'iconKey' => 'Clock',
                    'progress' => max(5, min(100, 100 - ($avgWaitToday * 4))),
                    'tag' => 'Queue',
                    'tagType' => $avgWaitToday > 10 ? 'warning' : 'success',
                    'color' => $avgWaitToday > 10 ? 'warning' : 'success',
                ],
            ];
        }, 10);

        return $this->respond($payload);
    }

    public function traffic(Request $request)
    {
        $range = $request->query('range', '7d');
        $startDate = match ($range) {
            '30d' => today()->subDays(29),
            'month' => today()->startOfMonth(),
            default => today()->subDays(6),
        };
        $cacheKey = sprintf('dashboard:dashboard:traffic:%s:%s', $range, $startDate->toDateString());
        $payload = $this->rememberPayload($cacheKey, function () use ($startDate): array {
            $appointmentCounts = $this->metrics->appointmentCountsByDate($startDate, today());
            $walkInCounts = $this->metrics->walkInCountsByDate($startDate, today());
            $dates = collect();
            $cursor = $startDate->copy();

            while ($cursor->lte(today())) {
                $dates->push($cursor->copy());
                $cursor->addDay();
            }

            return $dates->map(function (Carbon $date) use ($appointmentCounts, $walkInCounts) {
                $dateKey = $this->metrics->formatDateKey($date);

                return [
                    'day' => $date->format('M d'),
                    'appointments' => $appointmentCounts[$dateKey] ?? 0,
                    'walkins' => $walkInCounts[$dateKey] ?? 0,
                ];
            })->all();
        }, 20);

        return $this->respond($payload);
    }

    public function liveQueue()
    {
        $entry = QueueEntry::query()
            ->with(['customer', 'queueSession.branch', 'queueSession.service', 'walkInTicket'])
            ->whereHas('queueSession', fn ($query) => $query->whereDate('session_date', today()))
            ->whereIn('queue_status', ['serving', 'next', 'waiting'])
            ->orderByRaw("CASE queue_status WHEN 'serving' THEN 0 WHEN 'next' THEN 1 ELSE 2 END")
            ->orderBy('queue_position')
            ->first();

        if (! $entry) {
            return $this->respond([
                'ticketPrefix' => 'W',
                'ticketNumber' => 0,
                'ticketStart' => 0,
                'customerName' => 'No active customer',
                'serviceName' => 'No active service',
                'branchName' => 'No active branch',
                'queuePosition' => 0,
                'queuePositionStart' => 0,
                'queuePositionSuffix' => 'in queue',
                'estimatedWaitMinutes' => 0,
                'estimatedWaitStart' => 0,
            ]);
        }

        $ticketNumber = $entry->walkInTicket?->ticket_number ?? $entry->queue_position;
        $estimatedWait = $entry->queue_status->value === 'serving'
            ? 0
            : max(($entry->queue_position - 1) * ($entry->queueSession?->service?->average_service_duration_minutes ?? 10), 0);

        return $this->respond([
            'ticketPrefix' => $entry->appointment_id ? 'A' : 'W',
            'ticketNumber' => $ticketNumber,
            'ticketStart' => max($ticketNumber - 3, 0),
            'customerName' => $entry->customer?->full_name ?? 'Walk-in Customer',
            'serviceName' => $entry->queueSession?->service?->service_name ?? 'General Service',
            'branchName' => $entry->queueSession?->branch?->branch_name ?? 'Main Branch',
            'queuePosition' => $entry->queue_position,
            'queuePositionStart' => max($entry->queue_position - 2, 0),
            'queuePositionSuffix' => 'in queue',
            'estimatedWaitMinutes' => $estimatedWait,
            'estimatedWaitStart' => max($estimatedWait - 5, 0),
        ]);
    }

    public function queuePerformance()
    {
        $servedCount = QueueEntry::query()
            ->where('queue_status', 'completed')
            ->whereDate('updated_at', today())
            ->count();
        $cancelledCount = QueueEntry::query()
            ->where('queue_status', 'cancelled')
            ->whereDate('updated_at', today())
            ->count();
        $avgWaitToday = $this->metrics->averageWaitMinutes(today());
        $avgWaitYesterday = $this->metrics->averageWaitMinutes(today()->copy()->subDay());
        $efficiencyPercent = max(0, min(100, (int) round(($servedCount / max($servedCount + $cancelledCount, 1)) * 100)));

        $staffRows = StaffMember::query()
            ->select(['id', 'branch_id', 'full_name', 'is_online'])
            ->with('branch:id,branch_name')
            ->withCount([
                'servedQueueEntries as served_today_count' => fn ($query) => $query
                    ->whereDate('updated_at', today())
                    ->where('queue_status', 'completed'),
            ])
            ->orderByDesc('served_today_count')
            ->limit(5)
            ->get()
            ->map(function (StaffMember $staff) {
                $served = (int) $staff->served_today_count;

                return [
                    'id' => $staff->getKey(),
                    'initials' => DashboardFormatting::initials($staff->full_name),
                    'name' => $staff->full_name,
                    'branch' => $staff->branch?->branch_name ?? 'Main Branch',
                    'customers' => $served,
                    'avgService' => (($served * 2) + 6).'m',
                    'status' => $staff->is_online ? 'Online' : 'Offline',
                    'statusClass' => $staff->is_online ? 'text-emerald-600' : 'text-slate-500',
                    'avatarClass' => $staff->is_online ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700',
                ];
            })
            ->values()
            ->all();

        $activities = QueueEntry::query()
            ->select(['id', 'queue_session_id', 'customer_id', 'ticket_id', 'queue_status', 'updated_at'])
            ->with(['customer:id,full_name', 'queueSession.branch:id,branch_name'])
            ->whereDate('updated_at', today())
            ->latest('updated_at')
            ->take(6)
            ->get()
            ->map(function (QueueEntry $entry) {
                $status = $entry->queue_status->value;
                $iconKey = match ($status) {
                    'completed' => 'CheckCircle2',
                    'cancelled' => 'X',
                    default => 'LogIn',
                };

                return [
                    'id' => $entry->getKey(),
                    'title' => DashboardFormatting::titleCase($status).' ticket',
                    'description' => sprintf(
                        '%s at %s',
                        $entry->customer?->full_name ?? 'Customer',
                        $entry->queueSession?->branch?->branch_name ?? 'Main Branch'
                    ),
                    'time' => DashboardFormatting::compactTimeAgo($entry->updated_at),
                    'iconKey' => $iconKey,
                    'iconClass' => $status === 'completed' ? 'text-emerald-600' : ($status === 'cancelled' ? 'text-rose-600' : 'text-primary'),
                ];
            })
            ->values()
            ->all();

        return $this->respond([
            'lastUpdatedLabel' => 'Updated '.now()->format('h:i A'),
            'avgWaitTimeLabel' => $avgWaitToday.'m',
            'avgWaitChangeLabel' => $this->changeLabel($avgWaitToday, $avgWaitYesterday, inverse: true),
            'efficiencyPercent' => $efficiencyPercent,
            'servedCount' => $servedCount,
            'cancelledCount' => $cancelledCount,
            'peakHeatmapShades' => $this->heatmapShades(),
            'staffRows' => $staffRows,
            'activities' => $activities,
            'peakInsightMessage' => $avgWaitToday > 10
                ? 'Queue pressure is elevated. Consider opening another counter.'
                : 'Queue flow is stable across active branches.',
        ]);
    }

    protected function buildKpi(
        string $title,
        int $current,
        int $previous,
        string $iconKey,
        string $tag,
        string $tagType,
        string $color,
    ): array {
        return [
            'title' => $title,
            'value' => $current,
            'change' => $this->changeLabel($current, $previous),
            'changePositive' => $current >= $previous,
            'iconKey' => $iconKey,
            'progress' => max(5, min(100, $current > 0 ? $current : 10)),
            'tag' => $tag,
            'tagType' => $tagType,
            'color' => $color,
        ];
    }

    protected function changeLabel(int $current, int $previous, bool $inverse = false): string
    {
        if ($previous <= 0) {
            return $current > 0 ? '+100%' : '0%';
        }

        $delta = (int) round((($current - $previous) / $previous) * 100);

        if ($inverse) {
            $delta *= -1;
        }

        return ($delta >= 0 ? '+' : '').$delta.'%';
    }

    protected function averageWaitMinutes(Carbon $date): int
    {
        return $this->metrics->averageWaitMinutes($date);
    }

    protected function heatmapShades(): array
    {
        $counts = $this->metrics->queueActivityCountsByDate(today()->copy()->subDays(6), today());

        return collect(range(6, 0))
            ->map(function (int $offset) use ($counts) {
                $count = $counts[today()->copy()->subDays($offset)->toDateString()] ?? 0;

                return match (true) {
                    $count >= 20 => 'bg-rose-500/90',
                    $count >= 12 => 'bg-amber-500/90',
                    $count >= 6 => 'bg-sky-500/90',
                    default => 'bg-emerald-500/90',
                };
            })
            ->all();
    }
}
