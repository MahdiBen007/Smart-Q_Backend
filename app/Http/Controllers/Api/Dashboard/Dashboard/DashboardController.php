<?php

namespace App\Http\Controllers\Api\Dashboard\Dashboard;

use App\Enums\QueueEntryStatus;
use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\QueueEntry;
use App\Models\StaffMember;
use App\Models\WalkInTicket;
use App\Support\Dashboard\BookingCodeFormatter;
use App\Support\Dashboard\DashboardFormatting;
use App\Support\Dashboard\DashboardMetricsService;
use App\Support\Dashboard\OperationalWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends DashboardApiController
{
    public function __construct(
        protected DashboardMetricsService $metrics,
    ) {}

    public function kpis(Request $request)
    {
        $companyId = $this->currentCompanyId($request);
        $branchId = $this->shouldRestrictToAssignedBranch($request)
            ? $this->currentBranchId($request)
            : null;
        $serviceId = $this->shouldRestrictToAssignedService($request)
            ? $this->currentServiceId($request)
            : null;
        $cacheKey = sprintf('dashboard:kpis:branch:%s:service:%s', $branchId ?: 'all', $serviceId ?: 'all');
        $payload = $this->rememberScopedPayload($request, $cacheKey, function () use ($request, $companyId, $branchId, $serviceId): array {
            $appointmentsQuery = $this->scopeQueryByCompanyRelation(Appointment::query(), $request, 'branch');
            $walkInsQuery = $this->scopeQueryByCompanyRelation(WalkInTicket::query(), $request, 'branch');
            $branchesQuery = $this->scopeQueryByCompanyColumn(Branch::query(), $request);
            $appointmentsQuery = $this->scopeQueryByAssignedBranchColumn($appointmentsQuery, $request);
            $walkInsQuery = $this->scopeQueryByAssignedBranchColumn($walkInsQuery, $request);
            $branchesQuery = $this->scopeQueryByAssignedBranchColumn($branchesQuery, $request, 'id');
            $appointmentsQuery = $this->scopeQueryByAssignedServiceColumn($appointmentsQuery, $request);
            $walkInsQuery = $this->scopeQueryByAssignedServiceColumn($walkInsQuery, $request);

            $todayAppointments = (clone $appointmentsQuery)
                ->whereDate('appointment_date', today())
                ->count();
            $yesterdayAppointments = (clone $appointmentsQuery)
                ->whereDate('appointment_date', today()->copy()->subDay())
                ->count();
            $todayWalkIns = (clone $walkInsQuery)
                ->whereDate('created_at', today())
                ->count();
            $yesterdayWalkIns = (clone $walkInsQuery)
                ->whereDate('created_at', today()->copy()->subDay())
                ->count();
            $activeBranches = (clone $branchesQuery)
                ->where('branch_status', 'active')
                ->count();
            $totalBranches = max((clone $branchesQuery)->count(), 1);
            $avgWaitToday = $this->metrics->averageWaitMinutes(today(), $companyId, branchId: $branchId, serviceId: $serviceId);
            $avgWaitYesterday = $this->metrics->averageWaitMinutes(today()->copy()->subDay(), $companyId, branchId: $branchId, serviceId: $serviceId);

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
        $companyId = $this->currentCompanyId($request);
        $branchId = $this->shouldRestrictToAssignedBranch($request)
            ? $this->currentBranchId($request)
            : null;
        $serviceId = $this->shouldRestrictToAssignedService($request)
            ? $this->currentServiceId($request)
            : null;
        $cacheKey = sprintf(
            'dashboard:dashboard:traffic:%s:%s:branch:%s:service:%s',
            $range,
            $startDate->toDateString(),
            $branchId ?: 'all',
            $serviceId ?: 'all'
        );
        $payload = $this->rememberScopedPayload($request, $cacheKey, function () use ($startDate, $companyId, $branchId, $serviceId): array {
            $appointmentCounts = $this->metrics->appointmentCountsByDate($startDate, today(), $companyId, branchId: $branchId, serviceId: $serviceId);
            $walkInCounts = $this->metrics->walkInCountsByDate($startDate, today(), $companyId, branchId: $branchId, serviceId: $serviceId);
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

    public function liveQueue(Request $request)
    {
        $entryQuery = $this->scopeQueryByCompanyRelation(
            QueueEntry::query()
                ->with([
                    'customer:id,user_id,full_name',
                    'customer.user:id,user_type',
                    'appointment.customer:id,user_id,full_name',
                    'appointment.customer.user:id,user_type',
                    'appointment.branch:id,company_id,branch_name',
                    'appointment.branch.company:id,company_name',
                    'appointment.service:id,service_name,average_service_duration_minutes',
                    'queueSession.branch:id,company_id,branch_name',
                    'queueSession.branch.company:id,company_name',
                    'queueSession.service:id,service_name,average_service_duration_minutes',
                    'walkInTicket:id,ticket_number,customer_id,branch_id,service_id',
                    'walkInTicket.customer:id,user_id,full_name',
                    'walkInTicket.customer.user:id,user_type',
                    'walkInTicket.branch:id,company_id,branch_name',
                    'walkInTicket.branch.company:id,company_name',
                    'walkInTicket.service:id,service_name,average_service_duration_minutes',
                ])
                ->whereHas('queueSession', fn ($query) => $query->whereDate('session_date', today()))
                ->whereIn('queue_status', [
                    QueueEntryStatus::Serving->value,
                    QueueEntryStatus::Next->value,
                    QueueEntryStatus::Waiting->value,
                ])
                ->orderByRaw(
                    sprintf(
                        "CASE queue_status WHEN '%s' THEN 0 WHEN '%s' THEN 1 ELSE 2 END",
                        QueueEntryStatus::Serving->value,
                        QueueEntryStatus::Next->value,
                    )
                )
                ->orderBy('queue_position'),
            $request,
            'queueSession.branch'
        );
        $entryQuery = $this->scopeQueryByAssignedBranchRelation($entryQuery, $request, 'queueSession.branch');
        $entryQuery = $this->scopeQueryByAssignedServiceRelation($entryQuery, $request, 'queueSession', 'service_id');
        $entry = $entryQuery->lazy(100)->first(
            fn (QueueEntry $candidate) => $this->shouldExposeInLiveQueue($candidate)
        );

        if (! $entry) {
            return $this->respond([
                'hasActiveEntry' => false,
                'queueStatus' => null,
                'ticketId' => '--',
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
                'awaitingCheckIn' => false,
                'checkInGraceRemainingSeconds' => 0,
            ]);
        }

        $customerName = $entry->customer?->full_name
            ?? $entry->appointment?->customer?->full_name
            ?? $entry->walkInTicket?->customer?->full_name
            ?? 'Walk-in Customer';
        $service = $entry->queueSession?->service
            ?? $entry->appointment?->service
            ?? $entry->walkInTicket?->service;
        $branch = $entry->queueSession?->branch
            ?? $entry->appointment?->branch
            ?? $entry->walkInTicket?->branch;
        $ticketNumber = $entry->walkInTicket
            ? (int) $entry->walkInTicket->ticket_number
            : ($entry->appointment
                ? BookingCodeFormatter::appointmentReferenceNumber($entry->appointment)
                : $entry->queue_position);
        $ticketId = BookingCodeFormatter::queueEntryDisplayCode($entry);
        $isSpecialNeeds = ($entry->customer?->user?->user_type ?? $entry->appointment?->customer?->user?->user_type) === BookingCodeFormatter::SPECIAL_NEEDS_TYPE;
        $serviceDurationMinutes = max((int) ($service?->average_service_duration_minutes ?? 10), 1);
        $estimatedWait = $entry->queue_status->value === QueueEntryStatus::Serving->value
            ? 0
            : max(($entry->queue_position - 1) * $serviceDurationMinutes, 0);
        $isAwaitingCheckIn = $entry->appointment_id !== null
            && $entry->checked_in_at === null
            && in_array($entry->queue_status->value, [QueueEntryStatus::Serving->value, QueueEntryStatus::Next->value], true);
        $checkInGraceRemainingSeconds = 0;

        if ($isAwaitingCheckIn) {
            $timeoutSeconds = max((int) ($entry->wait_timeout_seconds ?: OperationalWorkflowService::ABSENT_CHECK_IN_GRACE_SECONDS), 1);
            $referenceStart = $entry->calling_started_at;
            $elapsedSeconds = $referenceStart === null
                ? 0
                : max($referenceStart->diffInSeconds(now(), false), 0);
            $checkInGraceRemainingSeconds = max($timeoutSeconds - $elapsedSeconds, 0);
        }

        return $this->respond([
            'hasActiveEntry' => true,
            'queueStatus' => $entry->queue_status->value,
            'ticketId' => $ticketId,
            'ticketPrefix' => $isSpecialNeeds ? BookingCodeFormatter::SPECIAL_NEEDS_CODE_PREFIX : ($entry->appointment_id ? 'A' : 'W'),
            'ticketNumber' => $ticketNumber,
            'ticketStart' => max($ticketNumber - 3, 0),
            'customerName' => $customerName,
            'serviceName' => $service?->service_name ?? 'General Service',
            'branchName' => $branch?->branch_name ?? 'Main Branch',
            'queuePosition' => $entry->queue_position,
            'queuePositionStart' => max($entry->queue_position - 2, 0),
            'queuePositionSuffix' => 'in queue',
            'estimatedWaitMinutes' => $estimatedWait,
            'estimatedWaitStart' => max($estimatedWait - 5, 0),
            'awaitingCheckIn' => $isAwaitingCheckIn,
            'checkInGraceRemainingSeconds' => $checkInGraceRemainingSeconds,
        ]);
    }

    public function queuePerformance(Request $request)
    {
        $companyId = $this->currentCompanyId($request);
        $branchId = $this->shouldRestrictToAssignedBranch($request)
            ? $this->currentBranchId($request)
            : null;
        $serviceId = $this->shouldRestrictToAssignedService($request)
            ? $this->currentServiceId($request)
            : null;
        $servedBaseQuery = $this->scopeQueryByCompanyRelation(QueueEntry::query(), $request, 'queueSession.branch');
        $servedBaseQuery = $this->scopeQueryByAssignedBranchRelation($servedBaseQuery, $request, 'queueSession.branch');
        $servedBaseQuery = $this->scopeQueryByAssignedServiceRelation($servedBaseQuery, $request, 'queueSession', 'service_id');
        $servedCount = (clone $servedBaseQuery)
            ->where('queue_status', 'completed')
            ->whereDate('updated_at', today())
            ->count();
        $cancelledCount = (clone $servedBaseQuery)
            ->where('queue_status', 'cancelled')
            ->whereDate('updated_at', today())
            ->count();
        $avgWaitToday = $this->metrics->averageWaitMinutes(today(), $companyId, branchId: $branchId, serviceId: $serviceId);
        $avgWaitYesterday = $this->metrics->averageWaitMinutes(today()->copy()->subDay(), $companyId, branchId: $branchId, serviceId: $serviceId);
        $efficiencyPercent = max(0, min(100, (int) round(($servedCount / max($servedCount + $cancelledCount, 1)) * 100)));
        $heatmap = $this->queuePerformanceHeatmap($companyId, $branchId, $serviceId);

        return $this->respond([
            'lastUpdatedLabel' => now()->format('h:i A'),
            'avgWaitTimeLabel' => $avgWaitToday.'m',
            'avgWaitChangeLabel' => $this->changeLabel($avgWaitToday, $avgWaitYesterday, inverse: true),
            'avgWaitTrend' => $this->queuePerformanceTrend($companyId, $branchId, $serviceId),
            'efficiencyPercent' => $efficiencyPercent,
            'servedCount' => $servedCount,
            'cancelledCount' => $cancelledCount,
            'peakHeatmapShades' => $heatmap['shades'],
            'heatmapStartLabel' => $heatmap['startLabel'],
            'peakHourLabel' => $heatmap['peakHourLabel'],
            'heatmapEndLabel' => $heatmap['endLabel'],
            'staffRows' => $this->queuePerformanceStaffRows($request),
            'activities' => $this->queuePerformanceActivities($request),
            'peakInsightMessage' => $this->queuePerformanceInsight(
                avgWaitToday: $avgWaitToday,
                servedCount: $servedCount,
                cancelledCount: $cancelledCount,
                peakHourLabel: $heatmap['peakHourLabel'],
            ),
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

    protected function shouldExposeInLiveQueue(QueueEntry $entry): bool
    {
        if (! BookingCodeFormatter::isSpecialNeedsEntry($entry)) {
            return true;
        }

        return $entry->checked_in_at !== null;
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

    protected function averageWaitMinutes(
        Carbon $date,
        ?string $companyId = null,
        ?string $branchId = null,
        ?string $serviceId = null,
    ): int
    {
        return $this->metrics->averageWaitMinutes($date, $companyId, $branchId, $serviceId);
    }

    protected function queuePerformanceTrend(
        ?string $companyId = null,
        ?string $branchId = null,
        ?string $serviceId = null,
    ): array
    {
        return collect(range(4, 0))
            ->map(function (int $offset) use ($companyId, $branchId, $serviceId) {
                $date = today()->copy()->subDays($offset);

                return [
                    'label' => $date->format('M d'),
                    'value' => $this->averageWaitMinutes($date, $companyId, $branchId, $serviceId),
                ];
            })
            ->all();
    }

    protected function queuePerformanceHeatmap(
        ?string $companyId = null,
        ?string $branchId = null,
        ?string $serviceId = null,
    ): array
    {
        $hours = range(8, 18);
        $counts = $this->metrics->queueActivityCountsByHour(today(), $companyId, $branchId, $serviceId);
        $maxCount = max(collect($hours)->map(fn (int $hour) => $counts[$hour] ?? 0)->all()) ?: 0;
        $peakHour = collect($hours)
            ->sortByDesc(fn (int $hour) => $counts[$hour] ?? 0)
            ->first() ?? 8;

        $shades = collect($hours)
            ->map(function (int $hour) use ($counts, $maxCount) {
                $count = $counts[$hour] ?? 0;

                if ($maxCount === 0 || $count === 0) {
                    return 'bg-slate-200/90 dark:bg-slate-700/70';
                }

                $ratio = $count / $maxCount;

                return match (true) {
                    $ratio >= 0.85 => 'bg-rose-500/90',
                    $ratio >= 0.6 => 'bg-amber-500/90',
                    $ratio >= 0.35 => 'bg-sky-500/90',
                    default => 'bg-emerald-500/90',
                };
            })
            ->all();

        return [
            'shades' => $shades,
            'startLabel' => $this->formatHourLabel(8),
            'peakHourLabel' => $maxCount > 0 ? $this->formatHourLabel($peakHour) : 'No peak yet',
            'endLabel' => $this->formatHourLabel(18),
        ];
    }

    protected function queuePerformanceStaffRows(Request $request): array
    {
        $query = $this->scopeQueryByCompanyColumn(
            StaffMember::query()
                ->select(['id', 'branch_id', 'full_name', 'is_online'])
                ->with('branch:id,branch_name')
                ->with([
                    'servedQueueEntries' => fn ($query) => $query
                        ->select(['id', 'served_by_staff_id', 'service_started_at', 'updated_at', 'queue_status'])
                        ->whereDate('updated_at', today())
                        ->where('queue_status', 'completed'),
                ])
                ->withCount([
                    'servedQueueEntries as served_today_count' => fn ($query) => $query
                        ->whereDate('updated_at', today())
                        ->where('queue_status', 'completed'),
                ])
                ->orderByDesc('served_today_count')
                ->limit(5),
            $request
        );
        $query = $this->scopeQueryByAssignedBranchColumn($query, $request);
        $query = $this->scopeQueryByAssignedServiceColumn($query, $request);

        return $query->get()
            ->map(function (StaffMember $staff) {
                $served = (int) $staff->served_today_count;
                $durations = $staff->servedQueueEntries
                    ->map(fn (QueueEntry $entry) => $entry->service_started_at
                        ? max($entry->service_started_at->diffInMinutes($entry->updated_at), 1)
                        : null)
                    ->filter(fn (?int $minutes) => $minutes !== null)
                    ->values();
                $averageServiceMinutes = $durations->isNotEmpty()
                    ? (int) round($durations->avg())
                    : null;
                $status = match (true) {
                    $served >= 6 && ($averageServiceMinutes === null || $averageServiceMinutes <= 12) => 'HIGH',
                    $served >= 3 => 'MEDIUM',
                    default => 'NEEDS ATTENTION',
                };

                return [
                    'id' => $staff->getKey(),
                    'initials' => DashboardFormatting::initials($staff->full_name),
                    'name' => $staff->full_name,
                    'branch' => $staff->branch?->branch_name ?? 'Main Branch',
                    'customers' => $served,
                    'avgService' => $averageServiceMinutes !== null
                        ? $averageServiceMinutes.'m'
                        : '--',
                    'status' => $status,
                    'statusClass' => match ($status) {
                        'HIGH' => 'border border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/50 dark:text-emerald-300',
                        'MEDIUM' => 'border border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/50 dark:text-amber-300',
                        default => 'border border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/50 dark:text-rose-300',
                    },
                    'avatarClass' => $staff->is_online ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700',
                ];
            })
            ->values()
            ->all();
    }

    protected function queuePerformanceActivities(Request $request): array
    {
        $query = $this->scopeQueryByCompanyRelation(
            QueueEntry::query()
                ->select(['id', 'queue_session_id', 'customer_id', 'appointment_id', 'ticket_id', 'queue_status', 'updated_at'])
                ->with([
                    'customer:id,full_name',
                    'appointment.customer:id,full_name',
                    'walkInTicket.customer:id,full_name',
                    'queueSession.branch:id,branch_name',
                ])
                ->whereDate('updated_at', today())
                ->latest('updated_at')
                ->take(6),
            $request,
            'queueSession.branch'
        );
        $query = $this->scopeQueryByAssignedBranchRelation($query, $request, 'queueSession.branch');
        $query = $this->scopeQueryByAssignedServiceRelation($query, $request, 'queueSession', 'service_id');

        return $query->get()
            ->map(function (QueueEntry $entry) {
                $status = $entry->queue_status->value;
                $iconKey = match ($status) {
                    'completed' => 'CheckCircle2',
                    'cancelled' => 'X',
                    default => 'LogIn',
                };
                $customerName = $entry->customer?->full_name
                    ?? $entry->appointment?->customer?->full_name
                    ?? $entry->walkInTicket?->customer?->full_name
                    ?? 'Customer';
                $title = match ($status) {
                    'serving' => 'Serving Started',
                    'cancelled' => 'Ticket Cancelled',
                    'completed' => 'Service Completed',
                    default => 'New Check-in',
                };

                return [
                    'id' => $entry->getKey(),
                    'title' => $title,
                    'description' => sprintf(
                        '%s at %s',
                        $customerName,
                        $entry->queueSession?->branch?->branch_name ?? 'Main Branch'
                    ),
                    'time' => DashboardFormatting::compactTimeAgo($entry->updated_at),
                    'iconKey' => $iconKey,
                    'iconClass' => match ($status) {
                        'completed' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
                        'cancelled' => 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300',
                        default => 'bg-sky-100 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300',
                    },
                ];
            })
            ->values()
            ->all();
    }

    protected function queuePerformanceInsight(
        int $avgWaitToday,
        int $servedCount,
        int $cancelledCount,
        string $peakHourLabel,
    ): string {
        if ($servedCount === 0 && $cancelledCount === 0) {
            return 'Queue activity has not started yet today.';
        }

        if ($cancelledCount > $servedCount && $cancelledCount > 0) {
            return 'Cancellation pressure is rising. Review no-show handling and branch staffing.';
        }

        if ($avgWaitToday >= 12) {
            return sprintf('Queue pressure is elevated near %s. Consider opening another counter.', $peakHourLabel);
        }

        return sprintf('Queue flow is stable. Peak activity is clustering around %s.', $peakHourLabel);
    }

    protected function formatHourLabel(int $hour): string
    {
        return Carbon::createFromTime($hour)->format('g A');
    }
}
