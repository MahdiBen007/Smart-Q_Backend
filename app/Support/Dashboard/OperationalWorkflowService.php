<?php

namespace App\Support\Dashboard;

use App\Enums\AppointmentStatus;
use App\Enums\QueueEntryStatus;
use App\Enums\QueueSessionStatus;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DailyQueueSession;
use App\Models\QrCodeToken;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\WalkInTicket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    public function openAppointmentTicket(Appointment $appointment): QueueEntry
    {
        return DB::transaction(function () use ($appointment) {
            $existing = QueueEntry::query()
                ->where('appointment_id', $appointment->getKey())
                ->whereNotIn('queue_status', [QueueEntryStatus::Completed, QueueEntryStatus::Cancelled])
                ->first();

            if ($existing) {
                return $existing;
            }

            $session = $this->ensureSession($appointment->branch, $appointment->service);

            $entry = QueueEntry::create([
                'queue_session_id' => $session->getKey(),
                'customer_id' => $appointment->customer_id,
                'queue_position' => $this->nextQueuePosition($session),
                'queue_status' => QueueEntryStatus::Waiting,
                'checked_in_at' => now(),
                'appointment_id' => $appointment->getKey(),
            ]);

            $appointment->update([
                'appointment_status' => AppointmentStatus::Active,
            ]);

            $this->appendAndResequence($session, $entry->getKey());

            return $entry->refresh();
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

            $this->appendAndResequence($session, $entry->getKey());

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
            $session = $entry->queueSession()->firstOrFail();

            $entry->update([
                'queue_status' => QueueEntryStatus::Completed,
            ]);

            if ($entry->walkInTicket) {
                $entry->walkInTicket->update([
                    'ticket_status' => TicketStatus::Completed,
                ]);
            }

            $remaining = $this->activeEntries($session)->pluck('id')->all();
            $this->resequence($session, $remaining, $remaining[0] ?? null);

            return $entry->refresh();
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
                ]);

                if ($entry->walkInTicket) {
                    $entry->walkInTicket->update([
                        'ticket_status' => TicketStatus::Escalated,
                    ]);
                }
            }

            $remaining = $this->activeEntries($session)->pluck('id')->all();
            $this->resequence($session, $remaining, $remaining[0] ?? null);

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
            $this->resequence($session, $orderedIds, $orderedIds[0] ?? null);

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

    protected function appendAndResequence(DailyQueueSession $session, string $newEntryId): void
    {
        $activeEntries = $this->activeEntries($session);
        $currentServingId = $activeEntries->firstWhere('queue_status', QueueEntryStatus::Serving)?->getKey();
        $orderedIds = $activeEntries->pluck('id')->all();

        if (! in_array($newEntryId, $orderedIds, true)) {
            $orderedIds[] = $newEntryId;
        }

        $this->resequence($session, $orderedIds, $currentServingId ?: $orderedIds[0] ?? null);
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

        $nextId = $orderedIds[1] ?? null;

        foreach ($orderedIds as $index => $entryId) {
            $entry = QueueEntry::query()->find($entryId);

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
