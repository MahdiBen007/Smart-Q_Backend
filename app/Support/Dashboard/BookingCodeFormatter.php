<?php

namespace App\Support\Dashboard;

use App\Models\Appointment;
use App\Models\WalkInTicket;
use Illuminate\Support\Str;

class BookingCodeFormatter
{
    public static function appointmentDisplayCode(Appointment $appointment): string
    {
        return sprintf(
            'A-%s-%s-%d',
            self::segmentCode($appointment->branch?->branch_name, 'BR'),
            self::segmentCode($appointment->service?->service_name, 'SV'),
            self::appointmentReferenceNumber((string) $appointment->getKey()),
        );
    }

    public static function appointmentShortCode(Appointment $appointment): string
    {
        return 'A-'.self::appointmentReferenceNumber((string) $appointment->getKey());
    }

    public static function appointmentReferenceNumber(string $appointmentId): int
    {
        $hash = 0;

        foreach (str_split($appointmentId) as $character) {
            $hash = (($hash * 31) + ord($character)) & 0xFFFFFFFF;
        }

        return 100 + ($hash % 900);
    }

    public static function walkInDisplayCode(WalkInTicket $ticket): string
    {
        return sprintf(
            'W-%s-%s-%d',
            self::segmentCode($ticket->branch?->branch_name, 'BR'),
            self::segmentCode($ticket->service?->service_name, 'SV'),
            (int) $ticket->ticket_number,
        );
    }

    public static function walkInShortCode(WalkInTicket $ticket): string
    {
        return 'W-'.((int) $ticket->ticket_number);
    }

    protected static function segmentCode(?string $value, string $fallback): string
    {
        $normalized = trim(Str::upper(Str::ascii((string) $value)));

        if ($normalized === '') {
            return $fallback;
        }

        $parts = preg_split('/[^A-Z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($parts) || $parts === []) {
            return $fallback;
        }

        if (count($parts) === 1) {
            return str_pad(substr($parts[0], 0, 2), 2, 'X');
        }

        $code = '';

        foreach ($parts as $part) {
            $code .= substr($part, 0, 1);

            if (strlen($code) >= 2) {
                break;
            }
        }

        return str_pad(substr($code, 0, 2), 2, 'X');
    }
}
