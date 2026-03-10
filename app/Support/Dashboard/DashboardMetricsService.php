<?php

namespace App\Support\Dashboard;

use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\WalkInTicket;
use Illuminate\Support\Carbon;

class DashboardMetricsService
{
    public function appointmentCountsByDate(Carbon $startDate, Carbon $endDate): array
    {
        return Appointment::query()
            ->selectRaw('appointment_date as metric_date, COUNT(*) as aggregate_count')
            ->whereBetween('appointment_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('metric_date')
            ->pluck('aggregate_count', 'metric_date')
            ->map(fn (mixed $count) => (int) $count)
            ->all();
    }

    public function walkInCountsByDate(Carbon $startDate, Carbon $endDate): array
    {
        return WalkInTicket::query()
            ->selectRaw('DATE(created_at) as metric_date, COUNT(*) as aggregate_count')
            ->whereBetween('created_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->groupBy('metric_date')
            ->pluck('aggregate_count', 'metric_date')
            ->map(fn (mixed $count) => (int) $count)
            ->all();
    }

    public function appointmentCountsByDateAndHour(Carbon $startDate, Carbon $endDate): array
    {
        return Appointment::query()
            ->selectRaw('appointment_date as metric_date, HOUR(appointment_time) as metric_hour, COUNT(*) as aggregate_count')
            ->whereBetween('appointment_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotNull('appointment_time')
            ->groupBy('metric_date', 'metric_hour')
            ->get()
            ->mapWithKeys(fn (object $row) => [
                $row->metric_date.'|'.str_pad((string) $row->metric_hour, 2, '0', STR_PAD_LEFT) => (int) $row->aggregate_count,
            ])
            ->all();
    }

    public function walkInCountsByDateAndHour(Carbon $startDate, Carbon $endDate): array
    {
        return WalkInTicket::query()
            ->selectRaw('DATE(created_at) as metric_date, HOUR(created_at) as metric_hour, COUNT(*) as aggregate_count')
            ->whereBetween('created_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->groupBy('metric_date', 'metric_hour')
            ->get()
            ->mapWithKeys(fn (object $row) => [
                $row->metric_date.'|'.str_pad((string) $row->metric_hour, 2, '0', STR_PAD_LEFT) => (int) $row->aggregate_count,
            ])
            ->all();
    }

    public function queueActivityCountsByDate(Carbon $startDate, Carbon $endDate): array
    {
        return QueueEntry::query()
            ->selectRaw('DATE(updated_at) as metric_date, COUNT(*) as aggregate_count')
            ->whereBetween('updated_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->groupBy('metric_date')
            ->pluck('aggregate_count', 'metric_date')
            ->map(fn (mixed $count) => (int) $count)
            ->all();
    }

    public function averageWaitMinutes(Carbon $date): int
    {
        $average = QueueEntry::query()
            ->join('daily_queue_sessions', 'daily_queue_sessions.id', '=', 'queue_entries.queue_session_id')
            ->join('services', 'services.id', '=', 'daily_queue_sessions.service_id')
            ->whereDate('daily_queue_sessions.session_date', $date->toDateString())
            ->whereIn('queue_entries.queue_status', ['waiting', 'next', 'serving'])
            ->selectRaw(
                'AVG(CASE WHEN queue_entries.queue_position > 1 '.
                'THEN (queue_entries.queue_position - 1) * COALESCE(services.average_service_duration_minutes, 10) '.
                'ELSE 0 END) as aggregate_wait'
            )
            ->value('aggregate_wait');

        return (int) round((float) ($average ?? 0));
    }

    public function formatDateKey(Carbon $date): string
    {
        return $date->toDateString();
    }
}
