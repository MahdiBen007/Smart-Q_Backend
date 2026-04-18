<?php

namespace App\Support\Dashboard;

use App\Enums\QueueEntryStatus;
use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\WalkInTicket;
use Carbon\Carbon;
use Illuminate\Support\Str;

class BookingCodeFormatter
{
    public const SPECIAL_NEEDS_TYPE = 'special_needs';

    public const SPECIAL_NEEDS_CODE_PREFIX = 'SN';

    public static function compactQueueTicketCode(
        ?string $companyName,
        ?string $branchName,
        ?string $serviceName,
        int $referenceNumber
    ): string
    {
        return sprintf(
            '%s%s%s-%d',
            self::segmentInitial($companyName, 'C'),
            self::segmentInitial($branchName, 'B'),
            self::segmentInitial($serviceName, 'S'),
            max($referenceNumber, 0),
        );
    }

    public static function appointmentDisplayCode(Appointment $appointment): string
    {
        $baseCode = self::compactQueueTicketCode(
            $appointment->branch?->company?->company_name,
            $appointment->branch?->branch_name,
            $appointment->service?->service_name,
            self::appointmentReferenceNumber($appointment),
        );

        return self::prefixedCode($baseCode, self::isSpecialNeedsAppointment($appointment));
    }

    public static function appointmentShortCode(Appointment $appointment): string
    {
        return self::appointmentDisplayCode($appointment);
    }

    public static function appointmentReferenceNumber(Appointment $appointment): int
    {
        $activePosition = self::activeAppointmentQueuePosition($appointment);
        if ($activePosition !== null) {
            return $activePosition;
        }

        $appointmentDate = $appointment->appointment_date
            ? Carbon::parse((string) $appointment->appointment_date)->toDateString()
            : null;

        if ($appointmentDate === null) {
            return max((int) $appointment->getKey(), 1);
        }

        $query = Appointment::query()
            ->whereDate('appointment_date', $appointmentDate);

        if ($appointment->branch_id) {
            $query->where('branch_id', $appointment->branch_id);
        }

        if ($appointment->exists && $appointment->getKey()) {
            $query->where('id', '<=', $appointment->getKey());
        }

        $reference = (int) $query->count();

        return max($reference, 1);
    }

    public static function walkInDisplayCode(WalkInTicket $ticket): string
    {
        $baseCode = self::compactQueueTicketCode(
            $ticket->branch?->company?->company_name,
            $ticket->branch?->branch_name,
            $ticket->service?->service_name,
            self::walkInReferenceNumber($ticket),
        );

        return self::prefixedCode($baseCode, self::isSpecialNeedsWalkIn($ticket));
    }

    public static function queueEntryDisplayCode(QueueEntry $entry): string
    {
        if ($entry->walkInTicket) {
            return self::walkInDisplayCode($entry->walkInTicket);
        }

        if ($entry->appointment) {
            return self::appointmentDisplayCode($entry->appointment);
        }

        $baseCode = self::compactQueueTicketCode(
            $entry->queueSession?->branch?->company?->company_name,
            $entry->queueSession?->branch?->branch_name,
            $entry->queueSession?->service?->service_name,
            max((int) $entry->queue_position, 0),
        );

        return self::prefixedCode($baseCode, self::isSpecialNeedsQueueEntry($entry));
    }

    public static function isSpecialNeedsEntry(QueueEntry $entry): bool
    {
        return self::isSpecialNeedsQueueEntry($entry);
    }

    public static function walkInShortCode(WalkInTicket $ticket): string
    {
        return 'W-'.self::walkInReferenceNumber($ticket);
    }

    public static function walkInReferenceNumber(WalkInTicket $ticket): int
    {
        $activePosition = self::activeWalkInQueuePosition($ticket);
        if ($activePosition !== null) {
            return $activePosition;
        }

        $ticketDate = $ticket->created_at
            ? Carbon::parse($ticket->created_at)->toDateString()
            : null;

        if ($ticketDate === null) {
            return max((int) $ticket->ticket_number, 1);
        }

        $query = WalkInTicket::query()->whereDate('created_at', $ticketDate);

        if ($ticket->branch_id) {
            $query->where('branch_id', $ticket->branch_id);
        }

        if ($ticket->exists && $ticket->getKey()) {
            $query->where('id', '<=', $ticket->getKey());
        }

        $reference = (int) $query->count();

        return max($reference, 1);
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

    protected static function segmentInitial(?string $value, string $fallback): string
    {
        $normalized = trim(Str::upper(Str::ascii((string) $value)));

        if ($normalized === '') {
            return substr(Str::upper($fallback), 0, 1);
        }

        $parts = preg_split('/[^A-Z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($parts) || $parts === []) {
            return substr(Str::upper($fallback), 0, 1);
        }

        return substr($parts[0], 0, 1);
    }

    protected static function prefixedCode(string $code, bool $isSpecialNeeds): string
    {
        if (! $isSpecialNeeds) {
            return $code;
        }

        return self::SPECIAL_NEEDS_CODE_PREFIX.'-'.$code;
    }

    protected static function isSpecialNeedsAppointment(Appointment $appointment): bool
    {
        $appointment->loadMissing('customer:id,user_id');

        return self::isSpecialNeedsUserId($appointment->customer?->user_id);
    }

    protected static function isSpecialNeedsWalkIn(WalkInTicket $ticket): bool
    {
        $ticket->loadMissing('customer:id,user_id');

        return self::isSpecialNeedsUserId($ticket->customer?->user_id);
    }

    protected static function isSpecialNeedsQueueEntry(QueueEntry $entry): bool
    {
        $entry->loadMissing([
            'customer:id,user_id',
            'appointment.customer:id,user_id',
            'walkInTicket.customer:id,user_id',
        ]);

        return self::isSpecialNeedsUserId($entry->customer?->user_id)
            || self::isSpecialNeedsUserId($entry->appointment?->customer?->user_id)
            || self::isSpecialNeedsUserId($entry->walkInTicket?->customer?->user_id);
    }

    protected static function isSpecialNeedsUserId(?string $userId): bool
    {
        if (! is_string($userId) || trim($userId) === '') {
            return false;
        }

        return User::query()
            ->whereKey($userId)
            ->where('user_type', self::SPECIAL_NEEDS_TYPE)
            ->exists();
    }

    protected static function activeAppointmentQueuePosition(Appointment $appointment): ?int
    {
        $entry = null;

        if ($appointment->relationLoaded('queueEntries')) {
            $entry = $appointment->queueEntries
                ->sortBy('queue_position')
                ->first(fn (QueueEntry $item) => self::isActiveQueueStatus($item->queue_status));
        } else {
            $entry = QueueEntry::query()
                ->where('appointment_id', $appointment->getKey())
                ->whereNotIn('queue_status', self::inactiveQueueStatuses())
                ->orderBy('queue_position')
                ->first();
        }

        if (! $entry) {
            return null;
        }

        return max((int) $entry->queue_position, 1);
    }

    protected static function activeWalkInQueuePosition(WalkInTicket $ticket): ?int
    {
        $entry = null;

        if ($ticket->relationLoaded('queueEntries')) {
            $entry = $ticket->queueEntries
                ->sortBy('queue_position')
                ->first(fn (QueueEntry $item) => self::isActiveQueueStatus($item->queue_status));
        } else {
            $entry = QueueEntry::query()
                ->where('ticket_id', $ticket->getKey())
                ->whereNotIn('queue_status', self::inactiveQueueStatuses())
                ->orderBy('queue_position')
                ->first();
        }

        if (! $entry) {
            return null;
        }

        return max((int) $entry->queue_position, 1);
    }

    protected static function isActiveQueueStatus(mixed $status): bool
    {
        $value = $status instanceof QueueEntryStatus
            ? $status->value
            : (string) $status;

        return ! in_array($value, self::inactiveQueueStatuses(), true);
    }

    protected static function inactiveQueueStatuses(): array
    {
        return [
            QueueEntryStatus::Completed->value,
            QueueEntryStatus::Cancelled->value,
        ];
    }
}
