<?php

namespace App\Support\Operations;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\OperationsSchedule;
use App\Models\Service;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationsScheduleTimeSlotService
{
    public function listSlots(string $branchId, string $serviceId, CarbonInterface $date): array
    {
        $context = $this->resolveBookingContext($branchId, $serviceId);
        $schedule = $this->resolveSchedulePayload(
            companyId: $context['company_id'],
            branchId: $branchId,
            serviceId: $serviceId,
        ) ?? $this->defaultSchedulePayload();

        $dateOnly = Carbon::parse($date->toDateString());
        if ($dateOnly->isPast() && ! $dateOnly->isToday()) {
            return [];
        }

        $workday = $this->normalizeWorkday($dateOnly->format('D'));
        if ($workday === null) {
            return [];
        }

        $sessions = $this->sessionsForWorkday($schedule, $workday);
        if ($sessions === []) {
            return [];
        }

        $slots = $this->slotsFromSessions($sessions);
        if ($slots === []) {
            return [];
        }

        $bookedCounts = $this->bookedCountsByTime(
            branchId: $branchId,
            serviceId: $serviceId,
            appointmentDate: $dateOnly->toDateString(),
        );

        $nowMinutes = null;
        if ($dateOnly->isToday()) {
            $now = Carbon::now();
            $nowMinutes = ((int) $now->format('H')) * 60 + ((int) $now->format('i'));
        }

        $response = [];

        foreach ($slots as $slot) {
            $startMinutes = $slot['start_minutes'];
            $endMinutes = $slot['end_minutes'] ?? null;
            $capacity = $slot['capacity'];
            if ($capacity <= 0) {
                continue;
            }

            if ($endMinutes !== null && (int) $endMinutes <= (int) $startMinutes) {
                continue;
            }

            if ($nowMinutes !== null && $startMinutes < $nowMinutes) {
                continue;
            }

            $timeKey = $this->minutesToTime($startMinutes);
            $endTimeKey = $endMinutes !== null
                ? $this->minutesToTime((int) $endMinutes)
                : $this->minutesToTime($startMinutes + 60);
            $booked = $bookedCounts[$timeKey] ?? 0;
            $remaining = max(0, $capacity - $booked);

            $response[] = [
                'time' => $timeKey,
                'end_time' => $endTimeKey,
                'label' => $timeKey.' - '.$endTimeKey,
                'available' => $remaining > 0,
                'remaining' => $remaining,
            ];
        }

        return $response;
    }

    public function ensureSlotIsBookable(
        string $branchId,
        string $serviceId,
        CarbonInterface $date,
        string $time,
    ): void {
        $slots = $this->listSlots($branchId, $serviceId, $date);
        $normalizedTime = $this->normalizeTime($time);
        if ($normalizedTime === null) {
            throw ValidationException::withMessages([
                'appointment_time' => ['Invalid appointment time.'],
            ]);
        }

        $match = null;
        foreach ($slots as $slot) {
            if (($slot['time'] ?? '') === $normalizedTime) {
                $match = $slot;
                break;
            }
        }

        if ($match === null) {
            throw ValidationException::withMessages([
                'appointment_time' => ['Selected time is not available on this date.'],
            ]);
        }

        if (! ($match['available'] ?? false)) {
            throw ValidationException::withMessages([
                'appointment_time' => ['Selected time slot is fully booked.'],
            ]);
        }
    }

    protected function resolveBookingContext(string $branchId, string $serviceId): array
    {
        $branch = Branch::query()->select(['id', 'company_id'])->findOrFail($branchId);

        $service = Service::query()
            ->select(['id', 'branch_id', 'is_active'])
            ->findOrFail($serviceId);

        $pivot = DB::table('branch_service')
            ->where('branch_id', $branchId)
            ->where('service_id', $serviceId)
            ->first(['is_active_override']);

        $assignedToBranch = $service->branch_id === $branchId || $pivot !== null;
        if (! $assignedToBranch) {
            throw (new ModelNotFoundException())->setModel(Service::class, [$serviceId]);
        }

        $activeOverride = $pivot?->is_active_override;
        $serviceActive = $activeOverride !== null ? (bool) $activeOverride : ((bool) ($service->is_active ?? true));
        if (! $serviceActive) {
            throw (new ModelNotFoundException())->setModel(Service::class, [$serviceId]);
        }

        return [
            'company_id' => $branch->company_id,
        ];
    }

    protected function resolveSchedulePayload(
        string $companyId,
        string $branchId,
        string $serviceId,
    ): ?array {
        $targetKeys = [
            'branch:'.$branchId,
            'service:'.$serviceId,
            'global',
        ];

        $schedules = OperationsSchedule::query()
            ->where('company_id', $companyId)
            ->whereIn('status', ['draft', 'published'])
            ->whereIn('target_key', $targetKeys)
            ->get()
            ->keyBy('target_key');

        foreach ($targetKeys as $key) {
            $schedule = $schedules->get($key);
            if ($schedule) {
                return is_array($schedule->schedule) ? $schedule->schedule : null;
            }
        }

        return null;
    }

    protected function defaultSchedulePayload(): array
    {
        $workdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

        $makeSession = function (string $id, string $start, string $end, int $duration, int $remote, int $inPerson): array {
            return [
                'id' => $id,
                'enabled' => true,
                'startTime' => $start,
                'endTime' => $end,
                'slotDurationMinutes' => $duration,
                'slots' => $this->generateSlots(
                    startTime: $start,
                    endTime: $end,
                    durationMinutes: $duration,
                    remote: $remote,
                    inPerson: $inPerson,
                ),
            ];
        };

        return [
            'workdays' => $workdays,
            'days' => array_map(function (string $day) use ($makeSession): array {
                return [
                    'day' => $day,
                    'sessions' => [
                        $makeSession('morning', '08:00', '12:00', 60, 10, 15),
                        $makeSession('evening', '14:00', '18:00', 60, 5, 10),
                    ],
                ];
            }, $workdays),
        ];
    }

    protected function sessionsForWorkday(array $schedule, string $workday): array
    {
        $workdays = $schedule['workdays'] ?? [];
        $normalizedWorkdays = array_values(array_filter(array_map(function ($day): ?string {
            if (! is_string($day)) {
                return null;
            }
            return $this->normalizeWorkday($day);
        }, is_array($workdays) ? $workdays : [])));

        if ($normalizedWorkdays !== [] && ! in_array($workday, $normalizedWorkdays, true)) {
            return [];
        }

        $days = $schedule['days'] ?? [];
        if (! is_array($days)) {
            return [];
        }

        foreach ($days as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $dayValue = $entry['day'] ?? null;
            if (! is_string($dayValue)) {
                continue;
            }

            if ($this->normalizeWorkday($dayValue) !== $workday) {
                continue;
            }

            $sessions = $entry['sessions'] ?? [];
            if (! is_array($sessions)) {
                return [];
            }

            return array_values(array_filter($sessions, fn ($session) => is_array($session)));
        }

        return [];
    }

    protected function slotsFromSessions(array $sessions): array
    {
        $slots = [];

        foreach ($sessions as $session) {
            if (! ($session['enabled'] ?? false)) {
                continue;
            }

            $duration = (int) ($session['slotDurationMinutes'] ?? 60);
            $duration = max(5, min(240, $duration));

            $startTime = is_string($session['startTime'] ?? null) ? (string) $session['startTime'] : '08:00';
            $endTime = is_string($session['endTime'] ?? null) ? (string) $session['endTime'] : '12:00';

            $sessionSlots = [];
            if (is_array($session['slots'] ?? null) && $session['slots'] !== []) {
                foreach ($session['slots'] as $slot) {
                    if (! is_array($slot)) {
                        continue;
                    }

                    $startMinutes = isset($slot['startMinutes']) ? (int) $slot['startMinutes'] : null;
                    $endMinutes = isset($slot['endMinutes']) ? (int) $slot['endMinutes'] : null;
                    if ($startMinutes === null || $endMinutes === null) {
                        continue;
                    }

                    $remote = (int) ($slot['remote'] ?? 0);
                    $inPerson = (int) ($slot['inPerson'] ?? 0);
                    $capacity = max(0, $remote + $inPerson);
                    if ($endMinutes <= $startMinutes) {
                        continue;
                    }

                    $sessionSlots[] = [
                        'start_minutes' => max(0, min(24 * 60, $startMinutes)),
                        'end_minutes' => max(0, min(24 * 60, $endMinutes)),
                        'capacity' => $capacity,
                    ];
                }
            } else {
                $generated = $this->generateSlots($startTime, $endTime, $duration, remote: 0, inPerson: 0);
                foreach ($generated as $slot) {
                    $sessionSlots[] = [
                        'start_minutes' => $slot['startMinutes'],
                        'end_minutes' => $slot['endMinutes'],
                        'capacity' => max(0, (int) ($slot['remote'] ?? 0) + (int) ($slot['inPerson'] ?? 0)),
                    ];
                }
            }

            foreach ($sessionSlots as $slot) {
                $slots[] = $slot;
            }
        }

        usort($slots, fn ($a, $b) => ($a['start_minutes'] ?? 0) <=> ($b['start_minutes'] ?? 0));

        $unique = [];
        $seen = [];
        foreach ($slots as $slot) {
            $key = (string) ($slot['start_minutes'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $slot;
        }

        return $unique;
    }

    protected function bookedCountsByTime(string $branchId, string $serviceId, string $appointmentDate): array
    {
        $appointments = Appointment::query()
            ->where('branch_id', $branchId)
            ->where('service_id', $serviceId)
            ->whereDate('appointment_date', $appointmentDate)
            ->whereIn('appointment_status', ['pending', 'confirmed', 'active'])
            ->whereNotNull('appointment_time')
            ->get(['appointment_time']);

        $counts = [];

        foreach ($appointments as $appointment) {
            $time = $this->normalizeTime((string) $appointment->appointment_time);
            if ($time === null) {
                continue;
            }
            $counts[$time] = ($counts[$time] ?? 0) + 1;
        }

        return $counts;
    }

    protected function normalizeTime(string $time): ?string
    {
        $trimmed = trim($time);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(?<h>[0-2]?[0-9]):(?<m>[0-5][0-9])/', $trimmed, $matches) !== 1) {
            return null;
        }

        $hour = (int) ($matches['h'] ?? 0);
        $minute = (int) ($matches['m'] ?? 0);

        if ($hour > 23) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    protected function normalizeWorkday(string $value): ?string
    {
        $candidate = strtolower(substr(trim($value), 0, 3));
        return match ($candidate) {
            'mon' => 'Mon',
            'tue' => 'Tue',
            'wed' => 'Wed',
            'thu' => 'Thu',
            'fri' => 'Fri',
            'sat' => 'Sat',
            'sun' => 'Sun',
            default => null,
        };
    }

    protected function timeLabel(string $time): string
    {
        try {
            return Carbon::createFromFormat('H:i', $time)->format('g:i A');
        } catch (\Throwable) {
            return $time;
        }
    }

    protected function minutesToTime(int $minutes): string
    {
        $safe = max(0, min(24 * 60, $minutes));
        $hours = intdiv($safe, 60);
        $mins = $safe % 60;

        return sprintf('%02d:%02d', $hours, $mins);
    }

    protected function generateSlots(
        string $startTime,
        string $endTime,
        int $durationMinutes,
        int $remote,
        int $inPerson,
    ): array {
        $startMinutes = $this->toMinutes($startTime);
        $endMinutes = $this->toMinutes($endTime);
        $step = max(5, min(240, $durationMinutes));

        if ($endMinutes <= $startMinutes) {
            return [];
        }

        $slots = [];

        for ($cursor = $startMinutes; $cursor + $step <= $endMinutes; $cursor += $step) {
            $slots[] = [
                'startMinutes' => $cursor,
                'endMinutes' => $cursor + $step,
                'remote' => $remote,
                'inPerson' => $inPerson,
            ];
        }

        return $slots;
    }

    protected function toMinutes(string $time): int
    {
        $normalized = $this->normalizeTime($time);
        if ($normalized === null) {
            return 0;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $normalized));

        return max(0, min(23, $hours)) * 60 + max(0, min(59, $minutes));
    }
}
