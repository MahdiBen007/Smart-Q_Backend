<?php

namespace App\Support\Dashboard;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class DashboardFormatting
{
    public static function appointmentStatusLabel(string $value): string
    {
        return match ($value) {
            'no_show' => 'No-Show',
            default => self::titleCase($value),
        };
    }

    public static function employmentStatusLabel(string $value): string
    {
        return match ($value) {
            'on_leave' => 'On Leave',
            default => self::titleCase($value),
        };
    }

    public static function queueStatusLabel(string $value): string
    {
        return match ($value) {
            'next' => 'Next',
            'serving' => 'Serving',
            default => 'Waiting',
        };
    }

    public static function queueSessionStatusLabel(string $value): string
    {
        return match ($value) {
            'closing_soon' => 'Closing Soon',
            default => self::titleCase($value),
        };
    }

    public static function ticketSourceLabel(string $value): string
    {
        return match ($value) {
            'qr_scan' => 'QR Scan',
            'staff_assisted' => 'Staff Assisted',
            default => self::titleCase($value),
        };
    }

    public static function titleCase(string $value): string
    {
        return Str::title(str_replace('_', ' ', $value));
    }

    public static function initials(string $value): string
    {
        $parts = collect(preg_split('/\s+/', trim($value)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::substr($part, 0, 1))
            ->implode('');

        return strtoupper($parts ?: Str::substr($value, 0, 2));
    }

    public static function shortDate(?CarbonInterface $value, string $fallback = '--'): string
    {
        return $value?->format('M d, Y') ?? $fallback;
    }

    public static function shortTime(CarbonInterface|string|null $value, string $fallback = '--'): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('h:i A');
        }

        if (is_string($value) && $value !== '') {
            return Str::upper(Carbon::parse($value)->format('h:i A'));
        }

        return $fallback;
    }

    public static function compactTimeAgo(?CarbonInterface $value, string $fallback = 'Now'): string
    {
        if (! $value) {
            return $fallback;
        }

        if ($value->diffInMinutes(now()) < 1) {
            return 'Now';
        }

        return $value->diffForHumans(now(), [
            'short' => true,
            'parts' => 1,
        ]);
    }

    public static function minutesLabel(?int $minutes, string $fallback = '--'): string
    {
        if ($minutes === null) {
            return $fallback;
        }

        if ($minutes <= 0) {
            return 'Live';
        }

        return $minutes.'m';
    }

    public static function serviceDurationLabel(?int $minutes, string $fallback = 'N/A'): string
    {
        return $minutes !== null ? $minutes.'m' : $fallback;
    }

    public static function trafficTone(int $minutes): string
    {
        return match (true) {
            $minutes >= 15 => 'high',
            $minutes >= 7 => 'normal',
            default => 'low',
        };
    }

    public static function serviceCounterLabel(int $position): string
    {
        return $position > 0 ? 'Counter '.(($position % 4) + 1) : 'Pending';
    }

    public static function displayTicketCode(string $prefix, int $number): string
    {
        return $prefix.'-'.$number;
    }
}
