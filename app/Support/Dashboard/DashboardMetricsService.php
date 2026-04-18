<?php

namespace App\Support\Dashboard;

use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\WalkInTicket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardMetricsService
{
    public function appointmentCountsByDate(
        Carbon $startDate,
        Carbon $endDate,
        ?string $companyId = null,
        ?string $branchId = null,
        ?string $serviceId = null,
    ): array
    {
        $query = Appointment::query()
            ->selectRaw('appointment_date as metric_date, COUNT(*) as aggregate_count')
            ->whereDate('appointment_date', '>=', $startDate->toDateString())
            ->whereDate('appointment_date', '<=', $endDate->toDateString())
            ->groupBy('metric_date');

        if ($serviceId !== null) {
            $query->where('service_id', $serviceId);
        }

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        } elseif ($companyId !== null) {
            $query->whereHas('branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId));
        }

        return $query
            ->get()
            ->mapWithKeys(fn (object $row) => [
                $this->normalizeDateKey($row->metric_date) => (int) $row->aggregate_count,
            ])
            ->all();
    }

    public function walkInCountsByDate(
        Carbon $startDate,
        Carbon $endDate,
        ?string $companyId = null,
        ?string $branchId = null,
        ?string $serviceId = null,
    ): array
    {
        $query = WalkInTicket::query()
            ->selectRaw('DATE(created_at) as metric_date, COUNT(*) as aggregate_count')
            ->whereBetween('created_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->groupBy('metric_date');

        if ($serviceId !== null) {
            $query->where('service_id', $serviceId);
        }

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        } elseif ($companyId !== null) {
            $query->whereHas('branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId));
        }

        return $query
            ->get()
            ->mapWithKeys(fn (object $row) => [
                $this->normalizeDateKey($row->metric_date) => (int) $row->aggregate_count,
            ])
            ->all();
    }

    public function appointmentCountsByDateAndHour(
        Carbon $startDate,
        Carbon $endDate,
        ?string $companyId = null,
        ?string $branchId = null,
        ?string $serviceId = null,
    ): array
    {
        $query = Appointment::query()
            ->selectRaw(
                sprintf(
                    'appointment_date as metric_date, %s as metric_hour, COUNT(*) as aggregate_count',
                    $this->hourExpression('appointment_time')
                )
            )
            ->whereDate('appointment_date', '>=', $startDate->toDateString())
            ->whereDate('appointment_date', '<=', $endDate->toDateString())
            ->whereNotNull('appointment_time')
            ->groupBy('metric_date', 'metric_hour');

        if ($serviceId !== null) {
            $query->where('service_id', $serviceId);
        }

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        } elseif ($companyId !== null) {
            $query->whereHas('branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId));
        }

        return $query
            ->get()
            ->mapWithKeys(fn (object $row) => [
                $this->normalizeDateKey($row->metric_date).'|'.str_pad((string) $row->metric_hour, 2, '0', STR_PAD_LEFT) => (int) $row->aggregate_count,
            ])
            ->all();
    }

    public function walkInCountsByDateAndHour(
        Carbon $startDate,
        Carbon $endDate,
        ?string $companyId = null,
        ?string $branchId = null,
        ?string $serviceId = null,
    ): array
    {
        $query = WalkInTicket::query()
            ->selectRaw(
                sprintf(
                    'DATE(created_at) as metric_date, %s as metric_hour, COUNT(*) as aggregate_count',
                    $this->hourExpression('created_at')
                )
            )
            ->whereBetween('created_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->groupBy('metric_date', 'metric_hour');

        if ($serviceId !== null) {
            $query->where('service_id', $serviceId);
        }

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        } elseif ($companyId !== null) {
            $query->whereHas('branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId));
        }

        return $query
            ->get()
            ->mapWithKeys(fn (object $row) => [
                $this->normalizeDateKey($row->metric_date).'|'.str_pad((string) $row->metric_hour, 2, '0', STR_PAD_LEFT) => (int) $row->aggregate_count,
            ])
            ->all();
    }

    public function queueActivityCountsByDate(
        Carbon $startDate,
        Carbon $endDate,
        ?string $companyId = null,
        ?string $branchId = null,
        ?string $serviceId = null,
    ): array
    {
        $query = QueueEntry::query()
            ->selectRaw('DATE(updated_at) as metric_date, COUNT(*) as aggregate_count')
            ->whereBetween('updated_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->groupBy('metric_date');

        if ($serviceId !== null) {
            $query->whereHas('queueSession', fn ($queueSessionQuery) => $queueSessionQuery->where('service_id', $serviceId));
        }

        if ($branchId !== null) {
            $query->whereHas('queueSession', fn ($queueSessionQuery) => $queueSessionQuery->where('branch_id', $branchId));
        } elseif ($companyId !== null) {
            $query->whereHas('queueSession.branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId));
        }

        return $query
            ->get()
            ->mapWithKeys(fn (object $row) => [
                $this->normalizeDateKey($row->metric_date) => (int) $row->aggregate_count,
            ])
            ->all();
    }

    public function queueActivityCountsByHour(
        Carbon $date,
        ?string $companyId = null,
        ?string $branchId = null,
        ?string $serviceId = null,
    ): array
    {
        $query = QueueEntry::query()
            ->selectRaw(
                sprintf('%s as metric_hour, COUNT(*) as aggregate_count', $this->hourExpression('updated_at'))
            )
            ->whereBetween('updated_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->groupBy('metric_hour');

        if ($serviceId !== null) {
            $query->whereHas('queueSession', fn ($queueSessionQuery) => $queueSessionQuery->where('service_id', $serviceId));
        }

        if ($branchId !== null) {
            $query->whereHas('queueSession', fn ($queueSessionQuery) => $queueSessionQuery->where('branch_id', $branchId));
        } elseif ($companyId !== null) {
            $query->whereHas('queueSession.branch', fn ($branchQuery) => $branchQuery->where('company_id', $companyId));
        }

        return $query
            ->pluck('aggregate_count', 'metric_hour')
            ->mapWithKeys(fn (mixed $count, mixed $hour) => [(int) $hour => (int) $count])
            ->all();
    }

    public function averageWaitMinutes(
        Carbon $date,
        ?string $companyId = null,
        ?string $branchId = null,
        ?string $serviceId = null,
    ): int
    {
        $query = QueueEntry::query()
            ->join('daily_queue_sessions', 'daily_queue_sessions.id', '=', 'queue_entries.queue_session_id')
            ->join('services', 'services.id', '=', 'daily_queue_sessions.service_id')
            ->whereDate('daily_queue_sessions.session_date', $date->toDateString())
            ->whereIn('queue_entries.queue_status', ['waiting', 'next', 'serving'])
            ->selectRaw(
                'AVG(CASE WHEN queue_entries.queue_position > 1 '.
                'THEN (queue_entries.queue_position - 1) * COALESCE(services.average_service_duration_minutes, 10) '.
                'ELSE 0 END) as aggregate_wait'
            );

        if ($serviceId !== null) {
            $query->where('daily_queue_sessions.service_id', $serviceId);
        }

        if ($branchId !== null) {
            $query->where('daily_queue_sessions.branch_id', $branchId);
        } elseif ($companyId !== null) {
            $query->join('branches', 'branches.id', '=', 'daily_queue_sessions.branch_id')
                ->where('branches.company_id', $companyId);
        }

        $average = $query->value('aggregate_wait');

        return (int) round((float) ($average ?? 0));
    }

    public function formatDateKey(Carbon $date): string
    {
        return $date->toDateString();
    }

    protected function hourExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', {$column}) AS INTEGER)"
            : "HOUR({$column})";
    }

    protected function normalizeDateKey(mixed $value): string
    {
        return Carbon::parse((string) $value)->toDateString();
    }
}
