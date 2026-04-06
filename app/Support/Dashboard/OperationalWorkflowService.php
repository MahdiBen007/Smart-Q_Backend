<?php

namespace App\Support\Dashboard;

use App\Enums\CheckInResult;
use App\Enums\AppointmentStatus;
use App\Enums\QueueEntryStatus;
use App\Enums\QueueSessionStatus;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TokenStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CheckInRecord;
use App\Models\Customer;
use App\Models\DailyQueueSession;
use App\Models\QrCodeToken;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\WalkInTicket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OperationalWorkflowService
{
    public function ensureSession(Branch $branch, Service $service): DailyQueueSession
    {
        return DailyQueueSession::query()->firstOrCreate(
            [
                'branch_id' => $branch->getKey(),
                'service_id' => $service->getKey(),
                'session_date' => now()->toDateString(),
            ],
            [
                'session_start_time' => '08:00:00',
                'session_end_time' => '18:00:00',
                'session_status' => QueueSessionStatus::Live,
            ],
        );
    }

    public function checkInAppointmentToken(
        string $tokenValue,
        ?string $kioskId = null,
        string $result = CheckInResult::Success->value,
    ): array {
        return DB::transaction(function () use ($tokenValue, $kioskId, $result) {
            $qrToken = QrCodeToken::query()
                ->with(['appointment.branch', 'appointment.service', 'appointment.customer'])
                ->lockForUpdate()
                ->where('token_value', $tokenValue)
                ->first();

            if (! $qrToken || ! $qrToken->appointment) {
                throw ValidationException::withMessages([
                    'token_value' => 'The scanned QR code is invalid for appointment check-in.',
                ]);
            }

            $appointment = $qrToken->appointment;

            if (
                in_array($appointment->appointment_status, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true)
            ) {
                throw ValidationException::withMessages([
                    'token_value' => 'This appointment is no longer available for check-in.',
                ]);
            }

            if (! $appointment->appointment_date?->isToday()) {
                throw ValidationException::withMessages([
                    'token_value' => 'Only same-day appointments can be checked in from the QR kiosk.',
                ]);
            }

            if (
                $qrToken->token_status === TokenStatus::Consumed
                || $qrToken->used_date_time !== null
            ) {
                throw ValidationException::withMessages([
                    'token_value' => 'This QR code has already been used.',
                ]);
            }

            if (
                $qrToken->token_status === TokenStatus::Expired
                || ($qrToken->expiration_date_time !== null && $qrToken->expiration_date_time->isPast())
            ) {
                $qrToken->update([
                    'token_status' => TokenStatus::Expired,
                ]);

                CheckInRecord::query()->create([
                    'qr_token_id' => $qrToken->getKey(),
                    'kiosk_id' => $kioskId,
                    'customer_id' => $appointment->customer_id,
                    'check_in_date_time' => now(),
                    'check_in_result' => CheckInResult::Pending,
                ]);

                throw ValidationException::withMessages([
                    'token_value' => 'This QR code has expired and needs manual assistance.',
                ]);
            }

            $queueEntry = $this->activateAppointmentQueueEntry($appointment, markCheckedIn: true);

            $checkInRecord = CheckInRecord::query()->create([
                'qr_token_id' => $qrToken->getKey(),
                'kiosk_id' => $kioskId,
                'customer_id' => $appointment->customer_id,
                'check_in_date_time' => now(),
                'check_in_result' => $result,
            ]);

            $qrToken->update([
                'used_date_time' => now(),
                'token_status' => TokenStatus::Consumed,
            ]);

            return [
                'appointment' => $appointment->fresh([
                    'branch',
                    'service',
                    'customer',
                    'staffMember',
                    'queueEntries',
                    'qrCodeTokens',
                ]),
                'queue_entry' => $queueEntry->fresh(),
                'check_in' => $checkInRecord->fresh(),
                'qr_token' => $qrToken->fresh(),
            ];
        });
    }

    public function registerWalkIn(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $branch = Branch::query()->findOrFail($payload['branch_id']);
            $service = Service::query()->findOrFail($payload['service_id']);
            $session = $this->ensureSession($branch, $service);

            $customer = isset($payload['customer_id'])
                ? Customer::query()->findOrFail($payload['customer_id'])
                : Customer::create([
                    'user_id' => null,
                    'full_name' => $payload['customer_name'] ?? 'Walk-in Customer',
                    'phone_number' => $payload['phone_number'] ?? $this->randomPhone(),
                    'email_address' => $payload['email_address'] ?? $this->randomEmail(),
                ]);

            $ticket = WalkInTicket::create([
                'customer_id' => $customer->getKey(),
                'branch_id' => $branch->getKey(),
                'service_id' => $service->getKey(),
                'queue_session_id' => $session->getKey(),
                'appointment_id' => $payload['appointment_id'] ?? null,
                'ticket_number' => $this->nextTicketNumber($branch),
                'ticket_source' => $payload['ticket_source'] ?? TicketSource::StaffAssisted,
                'ticket_status' => TicketStatus::Queued,
            ]);

            $entry = QueueEntry::create([
                'queue_session_id' => $session->getKey(),
                'customer_id' => $customer->getKey(),
                'queue_position' => $this->nextQueuePosition($session),
                'queue_status' => QueueEntryStatus::Waiting,
                'ticket_id' => $ticket->getKey(),
            ]);

            $token = QrCodeToken::create([
                'token_value' => Str::upper(Str::random(24)),
                'expiration_date_time' => now()->addHours(8),
                'token_status' => 'active',
                'ticket_id' => $ticket->getKey(),
            ]);

            $this->appendQueuedEntry($session, $entry->getKey());

            return [
                'customer' => $customer->refresh(),
                'ticket' => $ticket->refresh(),
                'queue_entry' => $entry->refresh(),
                'session' => $session->refresh(),
                'qr_token' => $token->refresh(),
            ];
        });
    }

    public function callEntry(QueueEntry $entry): QueueEntry
    {
        return DB::transaction(function () use ($entry) {
            $entry = $this->lockQueueEntry($entry);
            $this->ensureQueueEntryStatusAllowed(
                $entry,
                [QueueEntryStatus::Waiting, QueueEntryStatus::Next, QueueEntryStatus::Serving],
                'Only waiting or next tickets can be called into service.',
            );

            $session = $entry->queueSession()->firstOrFail();
            $activeIds = $this->activeEntries($session)
                ->reject(fn (QueueEntry $item) => $item->getKey() === $entry->getKey())
                ->prepend($entry)
                ->pluck('id')
                ->all();

            $entry->forceFill([
                'checked_in_at' => $entry->checked_in_at ?? now(),
            ])->save();

            $this->resequence($session, $activeIds, $entry->getKey());

            return $entry->refresh();
        });
    }

    public function skipEntry(QueueEntry $entry): QueueEntry
    {
        return DB::transaction(function () use ($entry) {
            $entry = $this->lockQueueEntry($entry);
            $this->ensureQueueEntryStatusAllowed(
                $entry,
                [QueueEntryStatus::Serving, QueueEntryStatus::Next],
                'Only serving or next tickets can be skipped.',
            );

            $session = $entry->queueSession()->firstOrFail();
            $ordered = $this->activeEntries($session)
                ->reject(fn (QueueEntry $item) => $item->getKey() === $entry->getKey())
                ->push($entry)
                ->values();

            $servingId = $ordered->first()?->getKey();
            $entry->forceFill([
                'service_started_at' => null,
            ])->save();

            $this->resequence($session, $ordered->pluck('id')->all(), $servingId);

            return $entry->refresh();
        });
    }

    public function completeEntry(QueueEntry $entry): QueueEntry
    {
        return DB::transaction(function () use ($entry) {
            $entry = $this->lockQueueEntry($entry);
            $this->ensureQueueEntryStatusAllowed(
                $entry,
                [QueueEntryStatus::Serving],
                'Only the currently serving ticket can be completed.',
            );

            $session = $entry->queueSession()->firstOrFail();

            $entry->update([
                'queue_status' => QueueEntryStatus::Completed,
            ]);

            if ($entry->walkInTicket) {
                $entry->walkInTicket->update([
                    'ticket_status' => TicketStatus::Completed,
                ]);
            }

            $this->archiveQueueEntry($session, $entry);

            $remaining = $this->activeEntries($session)->pluck('id')->all();
            $this->resequence($session, $remaining, $remaining[0] ?? null);

            return $entry->refresh();
        });
    }

    public function cancelAppointmentQueueEntries(Appointment $appointment): void
    {
        DB::transaction(function () use ($appointment) {
            $entries = QueueEntry::query()
                ->with('queueSession')
                ->where('appointment_id', $appointment->getKey())
                ->whereNotIn('queue_status', [QueueEntryStatus::Completed, QueueEntryStatus::Cancelled])
                ->get();

            $sessions = [];

            foreach ($entries as $entry) {
                $session = $entry->queueSession;

                if (! $session) {
                    continue;
                }

                $entry->update([
                    'queue_status' => QueueEntryStatus::Cancelled,
                    'service_started_at' => null,
                ]);

                $this->archiveQueueEntry($session, $entry, clearServiceStartedAt: true);
                $sessions[$session->getKey()] = $session;
            }

            foreach ($sessions as $session) {
                $this->normalizeActiveEntries($session);
            }
        });
    }

    public function clearWaitingEntries(DailyQueueSession $session): int
    {
        return DB::transaction(function () use ($session) {
            $waitingEntries = QueueEntry::query()
                ->where('queue_session_id', $session->getKey())
                ->where('queue_status', QueueEntryStatus::Waiting)
                ->with('walkInTicket')
                ->get();

            foreach ($waitingEntries as $entry) {
                $entry->update([
                    'queue_status' => QueueEntryStatus::Cancelled,
                    'service_started_at' => null,
                ]);

                if ($entry->walkInTicket) {
                    $entry->walkInTicket->update([
                        'ticket_status' => TicketStatus::Escalated,
                    ]);
                }

                $this->archiveQueueEntry($session, $entry, clearServiceStartedAt: true);
            }

            $this->normalizeActiveEntries($session);

            return $waitingEntries->count();
        });
    }

    public function resetSession(DailyQueueSession $session): int
    {
        return DB::transaction(function () use ($session) {
            $active = $this->activeEntries($session);

            QueueEntry::query()
                ->whereIn('id', $active->pluck('id'))
                ->update(['service_started_at' => null]);

            $orderedIds = $active->pluck('id')->all();
            $this->resequenceWithoutServing($session, $orderedIds, $orderedIds[0] ?? null);

            return count($orderedIds);
        });
    }

    public function updateSessionStatus(DailyQueueSession $session, string $status): DailyQueueSession
    {
        $session->update([
            'session_status' => $status,
        ]);

        return $session->refresh();
    }

    protected function activateAppointmentQueueEntry(
        Appointment $appointment,
        bool $markCheckedIn,
    ): QueueEntry {
        $existing = QueueEntry::query()
            ->where('appointment_id', $appointment->getKey())
            ->whereNotIn('queue_status', [QueueEntryStatus::Completed, QueueEntryStatus::Cancelled])
            ->first();

        if ($existing) {
            if ($markCheckedIn && ! $existing->checked_in_at) {
                $existing->forceFill([
                    'checked_in_at' => now(),
                ])->save();
            }

            if (! in_array($appointment->appointment_status, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true)) {
                $appointment->update([
                    'appointment_status' => AppointmentStatus::Active,
                ]);
            }

            return $existing->refresh();
        }

        $session = $this->ensureSession($appointment->branch, $appointment->service);

        $entry = QueueEntry::create([
            'queue_session_id' => $session->getKey(),
            'customer_id' => $appointment->customer_id,
            'queue_position' => $this->nextQueuePosition($session),
            'queue_status' => QueueEntryStatus::Waiting,
            'checked_in_at' => $markCheckedIn ? now() : null,
            'appointment_id' => $appointment->getKey(),
        ]);

        if (! in_array($appointment->appointment_status, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true)) {
            $appointment->update([
                'appointment_status' => AppointmentStatus::Active,
            ]);
        }

        $this->appendQueuedEntry($session, $entry->getKey());

        return $entry->refresh();
    }

    protected function appendQueuedEntry(DailyQueueSession $session, string $newEntryId): void
    {
        $activeEntries = $this->activeEntries($session);
        $currentServingId = $activeEntries->firstWhere('queue_status', QueueEntryStatus::Serving)?->getKey();
        $currentNextId = $activeEntries->firstWhere('queue_status', QueueEntryStatus::Next)?->getKey();
        $orderedIds = $activeEntries->pluck('id')->all();

        if (! in_array($newEntryId, $orderedIds, true)) {
            $orderedIds[] = $newEntryId;
        }

        if ($currentServingId) {
            $this->resequence($session, $orderedIds, $currentServingId);
            return;
        }

        $this->resequenceWithoutServing($session, $orderedIds, $currentNextId ?: $newEntryId);
    }

    protected function activeEntries(DailyQueueSession $session): Collection
    {
        return QueueEntry::query()
            ->where('queue_session_id', $session->getKey())
            ->whereNotIn('queue_status', [QueueEntryStatus::Completed, QueueEntryStatus::Cancelled])
            ->orderBy('queue_position')
            ->get();
    }

    protected function resequence(DailyQueueSession $session, array $orderedIds, ?string $servingId): void
    {
        $orderedIds = array_values(array_filter(array_unique($orderedIds)));

        if ($orderedIds === []) {
            return;
        }

        $nextId = $orderedIds[1] ?? null;
        $entries = $this->stageOrderedEntries($session, $orderedIds);

        foreach ($orderedIds as $index => $entryId) {
            $entry = $entries->get($entryId);

            if (! $entry) {
                continue;
            }

            $status = match (true) {
                $entryId === $servingId => QueueEntryStatus::Serving,
                $entryId === $nextId => QueueEntryStatus::Next,
                default => QueueEntryStatus::Waiting,
            };

            $entry->update([
                'queue_position' => $index + 1,
                'queue_status' => $status,
                'service_started_at' => $status === QueueEntryStatus::Serving
                    ? ($entry->service_started_at ?? now())
                    : null,
            ]);

            if ($entry->walkInTicket) {
                $entry->walkInTicket->update([
                    'ticket_status' => match ($status) {
                        QueueEntryStatus::Serving => TicketStatus::Serving,
                        QueueEntryStatus::Next, QueueEntryStatus::Waiting => $entry->checked_in_at
                            ? TicketStatus::CheckedIn
                            : TicketStatus::Queued,
                        default => TicketStatus::Queued,
                    },
                ]);
            }
        }
    }

    protected function resequenceWithoutServing(DailyQueueSession $session, array $orderedIds, ?string $nextId): void
    {
        $orderedIds = array_values(array_filter(array_unique($orderedIds)));

        if ($orderedIds === []) {
            return;
        }

        $entries = $this->stageOrderedEntries($session, $orderedIds);

        foreach ($orderedIds as $index => $entryId) {
            $entry = $entries->get($entryId);

            if (! $entry) {
                continue;
            }

            $status = $entryId === $nextId
                ? QueueEntryStatus::Next
                : QueueEntryStatus::Waiting;

            $entry->update([
                'queue_position' => $index + 1,
                'queue_status' => $status,
                'service_started_at' => null,
            ]);

            if ($entry->walkInTicket) {
                $entry->walkInTicket->update([
                    'ticket_status' => $entry->checked_in_at
                        ? TicketStatus::CheckedIn
                        : TicketStatus::Queued,
                ]);
            }
        }
    }

    protected function normalizeActiveEntries(DailyQueueSession $session): void
    {
        $activeEntries = $this->activeEntries($session);
        $orderedIds = $activeEntries->pluck('id')->all();

        if ($orderedIds === []) {
            return;
        }

        $servingId = $activeEntries->firstWhere('queue_status', QueueEntryStatus::Serving)?->getKey();

        if ($servingId) {
            $this->resequence($session, $orderedIds, $servingId);
            return;
        }

        $nextId = $activeEntries->firstWhere('queue_status', QueueEntryStatus::Next)?->getKey()
            ?? $orderedIds[0];

        $this->resequenceWithoutServing($session, $orderedIds, $nextId);
    }

    protected function archiveQueueEntry(
        DailyQueueSession $session,
        QueueEntry $entry,
        bool $clearServiceStartedAt = false,
    ): void {
        $payload = [
            'queue_position' => $this->nextQueuePosition($session),
        ];

        if ($clearServiceStartedAt) {
            $payload['service_started_at'] = null;
        }

        $entry->update($payload);
    }

    protected function stageOrderedEntries(DailyQueueSession $session, array $orderedIds): Collection
    {
        $entries = QueueEntry::query()
            ->lockForUpdate()
            ->where('queue_session_id', $session->getKey())
            ->whereIn('id', $orderedIds)
            ->get()
            ->keyBy(fn (QueueEntry $entry) => $entry->getKey());

        $temporaryPosition = $this->nextQueuePosition($session);

        foreach ($orderedIds as $index => $entryId) {
            $entry = $entries->get($entryId);

            if (! $entry) {
                continue;
            }

            $entry->update([
                'queue_position' => $temporaryPosition + $index,
            ]);
        }

        return $entries;
    }

    protected function lockQueueEntry(QueueEntry $entry): QueueEntry
    {
        return QueueEntry::query()
            ->with(['walkInTicket', 'queueSession'])
            ->lockForUpdate()
            ->findOrFail($entry->getKey());
    }

    protected function ensureQueueEntryStatusAllowed(
        QueueEntry $entry,
        array $allowedStatuses,
        string $message,
    ): void {
        $allowedValues = array_map(
            fn (QueueEntryStatus $status) => $status->value,
            $allowedStatuses,
        );

        if (in_array($entry->queue_status->value, $allowedValues, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'entry' => $message,
        ]);
    }

    protected function nextTicketNumber(Branch $branch): int
    {
        return (int) WalkInTicket::query()
            ->where('branch_id', $branch->getKey())
            ->max('ticket_number') + 1;
    }

    protected function nextQueuePosition(DailyQueueSession $session): int
    {
        return (int) QueueEntry::query()
            ->where('queue_session_id', $session->getKey())
            ->max('queue_position') + 1;
    }

    protected function randomPhone(): string
    {
        return '+2135'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    protected function randomEmail(): string
    {
        return 'walkin-'.Str::lower(Str::random(8)).'@smartqdz.local';
    }
}
