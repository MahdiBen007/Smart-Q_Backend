<?php

namespace App\Support\Operations;

use App\Models\Appointment;
use App\Models\BookingSlotLock;
use App\Models\Branch;
use App\Models\OperationsSchedule;
use App\Models\Service;
use App\Models\WalkInTicket;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationsScheduleTimeSlotService
{
    protected const MINIMUM_LEAD_MINUTES = 15;

    public function listSlots(
        string $branchId,
        string $serviceId,
        CarbonInterface $date,
        string $bookingChannel = 'in_person',
    ): array {
        $bookingChannel = $this->normalizeBookingChannel($bookingChannel);
        $context = $this->resolveBookingContext($branchId, $serviceId);
        $schedule = $this->resolveSchedulePayload(
            companyId: $context['company_id'],
            branchId: $branchId,
            serviceId: $serviceId,
        ) ?? $this->defaultSchedulePayload();

        $dateOnly = Carbon::parse($date->toDateString(), $this->bookingTimezone())->startOfDay();
        if ($dateOnly->lt(Carbon::today($this->bookingTimezone()))) {
            return [];
        }

        if (! $this->isWithinCurrentBookingCycle($schedule, $dateOnly)) {
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
            bookingChannel: $bookingChannel,
        );
        if ($bookingChannel === 'in_person') {
            $walkInCounts = $this->walkInCountsBySlotTime(
                branchId: $branchId,
                serviceId: $serviceId,
                ticketDate: $dateOnly->toDateString(),
                slots: $slots,
            );

            foreach ($walkInCounts as $time => $count) {
                $bookedCounts[$time] = ($bookedCounts[$time] ?? 0) + $count;
            }
        }

        $nowMinutes = null;
        if ($dateOnly->isToday()) {
            $now = Carbon::now($this->bookingTimezone())->addMinutes(self::MINIMUM_LEAD_MINUTES);
            $nowMinutes = ((int) $now->format('H')) * 60 + ((int) $now->format('i'));
        }

        $response = [];

        foreach ($slots as $slot) {
            $startMinutes = $slot['start_minutes'];
            $endMinutes = $slot['end_minutes'] ?? null;
            $capacity = $this->capacityForBookingChannel($slot, $bookingChannel);
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
                'session_id' => $slot['session_id'] ?? null,
                'session_title' => $slot['session_title'] ?? null,
                'period' => $slot['period'] ?? $this->periodForStartMinutes((int) $startMinutes),
                'capacity' => $capacity,
                'remote_capacity' => $slot['remote_capacity'] ?? 0,
                'in_person_capacity' => $slot['in_person_capacity'] ?? 0,
                'booking_channel' => $bookingChannel,
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
        string $bookingChannel = 'in_person',
    ): array {
        $slots = $this->listSlots($branchId, $serviceId, $date, $bookingChannel);
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

        return $match;
    }

    public function acquireBookingSlotLock(
        string $branchId,
        string $serviceId,
        CarbonInterface $date,
        string $time,
        string $bookingChannel,
    ): BookingSlotLock {
        $normalizedTime = $this->normalizeTime($time);
        if ($normalizedTime === null) {
            throw ValidationException::withMessages([
                'appointment_time' => ['Invalid appointment time.'],
            ]);
        }

        $attributes = [
            'branch_id' => $branchId,
            'service_id' => $serviceId,
            'slot_date' => Carbon::parse($date->toDateString(), $this->bookingTimezone())->toDateString(),
            'slot_start_time' => $normalizedTime.':00',
            'booking_channel' => $this->normalizeBookingChannel($bookingChannel),
        ];

        $this->ensureBookingSlotLockRow($attributes);

        return BookingSlotLock::query()
            ->where('branch_id', $attributes['branch_id'])
            ->where('service_id', $attributes['service_id'])
            ->whereDate('slot_date', $attributes['slot_date'])
            ->whereTime('slot_start_time', $attributes['slot_start_time'])
            ->where('booking_channel', $attributes['booking_channel'])
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function ensureCurrentWalkInSlotIsBookable(
        string $branchId,
        string $serviceId,
    ): array {
        $now = Carbon::now($this->bookingTimezone());
        $slots = $this->listSlots($branchId, $serviceId, $now, 'in_person');
        $nowMinutes = ((int) $now->format('H')) * 60 + ((int) $now->format('i'));

        $currentSlot = null;
        foreach ($slots as $slot) {
            $startMinutes = $this->toMinutes((string) ($slot['time'] ?? ''));
            $endMinutes = $this->toMinutes((string) ($slot['end_time'] ?? ''));
            if ($endMinutes <= $startMinutes) {
                continue;
            }

            if ($nowMinutes >= $startMinutes && $nowMinutes < $endMinutes) {
                $currentSlot = $slot;
                break;
            }
        }

        if ($currentSlot === null) {
            throw ValidationException::withMessages([
                'service_id' => ['Walk-ins are not available for this service at the current time.'],
            ]);
        }

        if (! ($currentSlot['available'] ?? false)) {
            throw ValidationException::withMessages([
                'service_id' => ['The walk-in capacity for the current time window is full.'],
            ]);
        }

        return $currentSlot;
    }

    public function ensureCurrentWalkInSlotIsBookableWithLock(
        string $branchId,
        string $serviceId,
    ): array {
        $candidate = $this->ensureCurrentWalkInSlotIsBookable($branchId, $serviceId);

        $this->acquireBookingSlotLock(
            branchId: $branchId,
            serviceId: $serviceId,
            date: Carbon::now($this->bookingTimezone()),
            time: (string) ($candidate['time'] ?? ''),
            bookingChannel: 'in_person',
        );

        return $this->ensureCurrentWalkInSlotIsBookable($branchId, $serviceId);
    }

    protected function ensureBookingSlotLockRow(array $attributes): void
    {
        try {
            BookingSlotLock::query()->firstOrCreate($attributes);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }
        }
    }

    protected function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true)
            || $driverCode === '19';
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
            throw (new ModelNotFoundException)->setModel(Service::class, [$serviceId]);
        }

        $activeOverride = $pivot?->is_active_override;
        $serviceActive = $activeOverride !== null ? (bool) $activeOverride : ((bool) ($service->is_active ?? true));
        if (! $serviceActive) {
            throw (new ModelNotFoundException)->setModel(Service::class, [$serviceId]);
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
            ->whereIn('target_key', $targetKeys)
            ->get()
            ->keyBy('target_key');

        foreach ($targetKeys as $key) {
            $schedule = $schedules->get($key);
            if (! $schedule) {
                continue;
            }

            if (is_array($schedule->published_schedule)) {
                return $schedule->published_schedule;
            }

            if ($schedule->status === 'published' && is_array($schedule->schedule)) {
                return $schedule->schedule;
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
        $normalizedWorkdays = $this->normalizedWorkdays($schedule);

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

    protected function isWithinCurrentBookingCycle(array $schedule, CarbonInterface $date): bool
    {
        $dateOnly = Carbon::parse($date->toDateString(), $this->bookingTimezone())->startOfDay();
        $today = Carbon::today($this->bookingTimezone());

        if ($dateOnly->lt($today)) {
            return false;
        }

        $currentWeekEnd = $today->copy()->endOfWeek(Carbon::SATURDAY)->startOfDay();
        if ($dateOnly->lte($currentWeekEnd)) {
            return true;
        }

        // If the current week has no remaining configured workday from today onward,
        // allow booking into the upcoming week window.
        if ($this->hasRemainingWorkdayInCurrentWeek($schedule, $today, $currentWeekEnd)) {
            return false;
        }

        $nextWeekStart = $currentWeekEnd->copy()->addDay();
        $nextWeekEnd = $currentWeekEnd->copy()->addDays(7);

        return $dateOnly->betweenIncluded($nextWeekStart, $nextWeekEnd);
    }

    protected function hasRemainingWorkdayInCurrentWeek(
        array $schedule,
        CarbonInterface $today,
        CarbonInterface $currentWeekEnd,
    ): bool {
        $workdays = $this->normalizedWorkdays($schedule);
        if ($workdays === []) {
            return false;
        }

        $todayDayOfWeek = (int) $today->dayOfWeek; // 0=Sun ... 6=Sat
        $endDayOfWeek = (int) $currentWeekEnd->dayOfWeek;

        foreach ($workdays as $workday) {
            $workdayIndex = $this->workdayDayOfWeekIndex($workday);
            if ($workdayIndex === null) {
                continue;
            }

            if ($workdayIndex >= $todayDayOfWeek && $workdayIndex <= $endDayOfWeek) {
                return true;
            }
        }

        return false;
    }

    protected function normalizedWorkdays(array $schedule): array
    {
        $workdays = $schedule['workdays'] ?? [];
        if (! is_array($workdays)) {
            return [];
        }

        $normalized = [];
        foreach ($workdays as $day) {
            if (! is_string($day)) {
                continue;
            }

            $workday = $this->normalizeWorkday($day);
            if ($workday !== null && ! in_array($workday, $normalized, true)) {
                $normalized[] = $workday;
            }
        }

        return $normalized;
    }

    protected function workdayIndex(string $workday): ?int
    {
        return match ($workday) {
            'Mon' => 1,
            'Tue' => 2,
            'Wed' => 3,
            'Thu' => 4,
            'Fri' => 5,
            'Sat' => 6,
            'Sun' => 7,
            default => null,
        };
    }

    protected function workdayDayOfWeekIndex(string $workday): ?int
    {
        return match ($workday) {
            'Sun' => Carbon::SUNDAY,
            'Mon' => Carbon::MONDAY,
            'Tue' => Carbon::TUESDAY,
            'Wed' => Carbon::WEDNESDAY,
            'Thu' => Carbon::THURSDAY,
            'Fri' => Carbon::FRIDAY,
            'Sat' => Carbon::SATURDAY,
            default => null,
        };
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
            $sessionId = is_string($session['id'] ?? null) && trim((string) $session['id']) !== ''
                ? trim((string) $session['id'])
                : $this->periodForStartMinutes($this->toMinutes($startTime));
            $sessionTitle = is_string($session['title'] ?? null) ? trim((string) $session['title']) : '';
            $sessionPeriod = $this->normalizePeriod($sessionId) ?? $this->periodForStartMinutes($this->toMinutes($startTime));

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
                        'remote_capacity' => max(0, $remote),
                        'in_person_capacity' => max(0, $inPerson),
                        'session_id' => $sessionId,
                        'session_title' => $sessionTitle,
                        'period' => $sessionPeriod,
                    ];
                }
            } else {
                $generated = $this->generateSlots($startTime, $endTime, $duration, remote: 0, inPerson: 0);
                foreach ($generated as $slot) {
                    $sessionSlots[] = [
                        'start_minutes' => $slot['startMinutes'],
                        'end_minutes' => $slot['endMinutes'],
                        'capacity' => max(0, (int) ($slot['remote'] ?? 0) + (int) ($slot['inPerson'] ?? 0)),
                        'remote_capacity' => max(0, (int) ($slot['remote'] ?? 0)),
                        'in_person_capacity' => max(0, (int) ($slot['inPerson'] ?? 0)),
                        'session_id' => $sessionId,
                        'session_title' => $sessionTitle,
                        'period' => $sessionPeriod,
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

    protected function bookedCountsByTime(
        string $branchId,
        string $serviceId,
        string $appointmentDate,
        string $bookingChannel,
    ): array {
        $appointments = Appointment::query()
            ->where('branch_id', $branchId)
            ->where('service_id', $serviceId)
            ->whereDate('appointment_date', $appointmentDate)
            ->whereIn('appointment_status', ['pending', 'confirmed', 'active'])
            ->where(function ($query) use ($bookingChannel): void {
                $query
                    ->where('appointment_channel', $bookingChannel)
                    ->when($bookingChannel === 'in_person', function ($query): void {
                        $query->orWhereNull('appointment_channel');
                    });
            })
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

    protected function walkInCountsBySlotTime(
        string $branchId,
        string $serviceId,
        string $ticketDate,
        array $slots,
    ): array {
        $tickets = WalkInTicket::query()
            ->where('branch_id', $branchId)
            ->where('service_id', $serviceId)
            ->whereDate('created_at', $ticketDate)
            ->get(['slot_start_time', 'created_at']);

        $counts = [];

        foreach ($tickets as $ticket) {
            $slotStart = $this->normalizeTime((string) $ticket->slot_start_time);
            if ($slotStart === null && $ticket->created_at) {
                $createdAt = $ticket->created_at->copy()->timezone($this->bookingTimezone());
                $ticketMinutes = ((int) $createdAt->format('H')) * 60 + ((int) $createdAt->format('i'));
                $slotStart = $this->slotStartForMinutes($slots, $ticketMinutes);
            }

            if ($slotStart === null) {
                continue;
            }

            $counts[$slotStart] = ($counts[$slotStart] ?? 0) + 1;
        }

        return $counts;
    }

    protected function slotStartForMinutes(array $slots, int $minutes): ?string
    {
        foreach ($slots as $slot) {
            $startMinutes = (int) ($slot['start_minutes'] ?? 0);
            $endMinutes = (int) ($slot['end_minutes'] ?? 0);
            if ($endMinutes <= $startMinutes) {
                continue;
            }

            if ($minutes >= $startMinutes && $minutes < $endMinutes) {
                return $this->minutesToTime($startMinutes);
            }
        }

        return null;
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

    protected function normalizePeriod(string $value): ?string
    {
        $candidate = strtolower(trim($value));

        return match (true) {
            str_contains($candidate, 'morning'), str_contains($candidate, 'am') => 'morning',
            str_contains($candidate, 'evening'), str_contains($candidate, 'pm'), str_contains($candidate, 'afternoon') => 'evening',
            default => null,
        };
    }

    protected function normalizeBookingChannel(string $value): string
    {
        $candidate = strtolower(trim($value));

        return match ($candidate) {
            'remote', 'online' => 'remote',
            default => 'in_person',
        };
    }

    protected function capacityForBookingChannel(array $slot, string $bookingChannel): int
    {
        if ($bookingChannel === 'remote') {
            return max(0, (int) ($slot['remote_capacity'] ?? 0));
        }

        return max(0, (int) ($slot['in_person_capacity'] ?? $slot['capacity'] ?? 0));
    }

    protected function periodForStartMinutes(int $startMinutes): string
    {
        return $startMinutes < 12 * 60 ? 'morning' : 'evening';
    }

    protected function bookingTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
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
