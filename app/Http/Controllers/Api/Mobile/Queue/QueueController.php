<?php

namespace App\Http\Controllers\Api\Mobile\Queue;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Models\Notification;
use App\Models\QueueEntry;
use App\Models\User;
use App\Support\Dashboard\BookingCodeFormatter;
use App\Support\Dashboard\OperationalWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class QueueController extends MobileApiController
{
    public function status(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $customer = $user->customer;

        if (! $customer) {
            return $this->respond([
                'user_ticket' => '--',
                'currently_serving' => '--',
                'current_counter' => '--',
                'queue_position' => 0,
                'estimated_wait_minutes' => 0,
                'awaiting_check_in' => false,
                'check_in_grace_remaining_seconds' => 0,
                'skipped_by_staff' => false,
                'skip_notice_id' => null,
                'timeline' => [],
            ]);
        }

        $entry = $this->resolveActiveCustomerQueueEntry($customer->getKey());

        if (! $entry) {
            return $this->respond([
                'user_ticket' => '--',
                'currently_serving' => '--',
                'current_counter' => '--',
                'queue_position' => 0,
                'estimated_wait_minutes' => 0,
                'awaiting_check_in' => false,
                'check_in_grace_remaining_seconds' => 0,
                'skipped_by_staff' => false,
                'skip_notice_id' => null,
                'timeline' => [],
            ]);
        }

        $isSpecialNeeds = BookingCodeFormatter::isSpecialNeedsEntry($entry);
        if ($isSpecialNeeds && $entry->checked_in_at === null) {
            return $this->respond([
                'user_ticket' => '--',
                'currently_serving' => '--',
                'current_counter' => '--',
                'queue_position' => 0,
                'estimated_wait_minutes' => 0,
                'awaiting_check_in' => false,
                'check_in_grace_remaining_seconds' => 0,
                'skipped_by_staff' => false,
                'skip_notice_id' => null,
                'timeline' => [],
            ]);
        }

        $session = $entry->queueSession;
        $activeDeskEntry = $this->resolveQueueMonitorActiveDeskEntry($entry);
        $entryStatus = $this->queueStatusValue($entry);
        $queuePosition = $isSpecialNeeds ? 0 : max(0, (int) $entry->queue_position);
        $userTicketCode = $this->entryDisplayCode($entry);
        $currentlyServingCode = $activeDeskEntry
            ? $this->entryDisplayCode($activeDeskEntry)
            : (in_array($entryStatus, ['serving', 'next'], true) ? $userTicketCode : '--');
        $isAwaitingCheckIn = $entry->appointment_id !== null
            && $entry->checked_in_at === null
            && in_array($entryStatus, ['serving', 'next'], true);
        $graceRemainingSeconds = 0;

        if ($isAwaitingCheckIn) {
            $timeoutSeconds = max((int) ($entry->wait_timeout_seconds ?: OperationalWorkflowService::ABSENT_CHECK_IN_GRACE_SECONDS), 1);
            $reference = $entry->calling_started_at;
            $elapsed = $reference ? max($reference->diffInSeconds(now(), false), 0) : 0;
            $graceRemainingSeconds = max($timeoutSeconds - $elapsed, 0);
        }
        $isCheckedIn = $entry->checked_in_at !== null;
        $isInService = in_array($entryStatus, ['serving', 'next'], true);
        $isCompleted = $entryStatus === 'completed';
        $hasServiceWindowExpiredWithoutCheckIn =
            ! $isCheckedIn &&
            $isInService &&
            $graceRemainingSeconds <= 0;
        $skipNoticeId = $this->latestSkipNoticeIdForEntry(
            userId: $user->getKey(),
            entry: $entry,
        );

        return $this->respond([
            'user_ticket' => $userTicketCode,
            'currently_serving' => $currentlyServingCode,
            'current_counter' => $session?->branch?->branch_name ?? 'Guichet',
            'queue_position' => $queuePosition,
            'estimated_wait_minutes' => $this->estimatedWaitMinutes($entry),
            'awaiting_check_in' => $isAwaitingCheckIn,
            'check_in_grace_remaining_seconds' => $graceRemainingSeconds,
            'skipped_by_staff' => $skipNoticeId !== null,
            'skip_notice_id' => $skipNoticeId,
            'timeline' => [
                [
                    'title' => 'Booking confirmed',
                    'subtitle' => 'Your booking was created successfully.',
                    'is_completed' => true,
                    'is_active' => false,
                    'is_failed' => false,
                ],
                [
                    'title' => 'Checked in',
                    'subtitle' => 'Your check-in was confirmed at the branch.',
                    'is_completed' => $isCheckedIn,
                    'is_active' => ! $isCheckedIn && ! $isCompleted,
                    'is_failed' => false,
                ],
                [
                    'title' => 'Now serving',
                    'subtitle' => 'Your booking code is now visible in Queue Monitor.',
                    'is_completed' => ($isCheckedIn && $isInService) || $isCompleted,
                    'is_active' => $isCheckedIn && $isInService && ! $isCompleted,
                    'is_failed' => $hasServiceWindowExpiredWithoutCheckIn,
                ],
                [
                    'title' => 'Service completed successfully',
                    'subtitle' => 'Your request has been completed successfully.',
                    'is_completed' => $isCompleted,
                    'is_active' => false,
                    'is_failed' => false,
                ],
            ],
        ]);
    }

    protected function latestSkipNoticeIdForEntry(string $userId, QueueEntry $entry): ?string
    {
        $query = Notification::query()
            ->where('user_id', $userId)
            ->where('notification_type', 'queue')
            ->where('action_path', '/live-queue-status')
            ->where('tone', 'warning')
            ->where('occurred_at', '>=', now()->subDay());

        $serviceName = $entry->queueSession?->service?->service_name;
        $branchName = $entry->queueSession?->branch?->branch_name;
        $normalizedServiceName = is_string($serviceName) ? mb_strtolower(trim($serviceName)) : '';
        $normalizedBranchName = is_string($branchName) ? mb_strtolower(trim($branchName)) : '';
        $notification = $query
            ->latest('occurred_at')
            ->limit(40)
            ->get(['id', 'message_content', 'description', 'occurred_at'])
            ->first(function (Notification $candidate) use ($normalizedServiceName, $normalizedBranchName): bool {
                $message = mb_strtolower((string) $candidate->message_content);
                $description = mb_strtolower((string) $candidate->description);

                $isSkipNotification = str_contains($message, 'turn was skipped')
                    || str_contains($message, 'moved to position')
                    || str_contains($message, 'cancelled by staff');

                if (! $isSkipNotification) {
                    return false;
                }

                if ($normalizedServiceName !== '' && ! str_contains($description, $normalizedServiceName)) {
                    return false;
                }

                if ($normalizedBranchName !== '' && ! str_contains($description, $normalizedBranchName)) {
                    return false;
                }

                return true;
            });

        if (! $notification) {
            return null;
        }

        $id = $notification->getKey();

        return is_string($id) && trim($id) !== '' ? $id : null;
    }

    protected function entryDisplayCode(QueueEntry $entry): string
    {
        return BookingCodeFormatter::queueEntryDisplayCode($entry);
    }

    protected function queueStatusValue(QueueEntry $entry): string
    {
        return is_object($entry->queue_status)
            ? (string) ($entry->queue_status->value ?? '')
            : (string) $entry->queue_status;
    }

    protected function estimatedWaitMinutes(QueueEntry $entry): int
    {
        if (BookingCodeFormatter::isSpecialNeedsEntry($entry)) {
            return 0;
        }

        $serviceDurationMinutes = max(
            (int) ($entry->queueSession?->service?->average_service_duration_minutes ?? 10),
            1
        );

        if ($this->queueStatusValue($entry) === 'serving') {
            return 0;
        }

        return max((((int) $entry->queue_position) - 1) * $serviceDurationMinutes, 0);
    }

    protected function resolveQueueMonitorActiveDeskEntry(QueueEntry $entry): ?QueueEntry
    {
        if (! $entry->queue_session_id) {
            return null;
        }

        return $this->queueMonitorSessionEntries($entry->queue_session_id)->first();
    }

    protected function queueMonitorSessionEntries(string $queueSessionId): Collection
    {
        return QueueEntry::query()
            ->with($this->queueEntryRelations())
            ->where('queue_session_id', $queueSessionId)
            ->whereNotIn('queue_status', ['completed', 'cancelled'])
            ->orderBy('queue_position')
            ->get()
            ->filter(fn (QueueEntry $entry): bool => $this->shouldExposeInQueueMonitor($entry))
            ->sortBy(function (QueueEntry $entry): array {
                $isCheckedInSpecialNeeds = BookingCodeFormatter::isSpecialNeedsEntry($entry)
                    && $entry->checked_in_at !== null;

                return [
                    $isCheckedInSpecialNeeds ? 0 : 1,
                    (int) $entry->queue_position,
                ];
            })
            ->values();
    }

    protected function shouldExposeInQueueMonitor(QueueEntry $entry): bool
    {
        if (! BookingCodeFormatter::isSpecialNeedsEntry($entry)) {
            return true;
        }

        return $entry->checked_in_at !== null;
    }

    protected function resolveActiveCustomerQueueEntry(string $customerId): ?QueueEntry
    {
        $today = now()->toDateString();

        $todayEntry = $this->activeCustomerQueueEntriesQuery($customerId)
            ->whereHas('queueSession', fn ($query) => $query->whereDate('session_date', $today))
            ->first();

        if ($todayEntry) {
            return $todayEntry;
        }

        $activeEntry = $this->activeCustomerQueueEntriesQuery($customerId)->first();
        if ($activeEntry) {
            return $activeEntry;
        }

        // Do not fallback to completed entries for live queue.
        // Once the ticket is marked as served, mobile live page should exit queue mode.
        return null;
    }

    protected function activeCustomerQueueEntriesQuery(string $customerId)
    {
        return QueueEntry::query()
            ->with($this->queueEntryRelations())
            ->where('customer_id', $customerId)
            ->whereNotIn('queue_status', ['completed', 'cancelled'])
            ->orderByRaw("
                CASE queue_status
                    WHEN 'serving' THEN 0
                    WHEN 'next' THEN 1
                    WHEN 'waiting' THEN 2
                    ELSE 3
                END
            ")
            ->orderBy('queue_position')
            ->orderByDesc('updated_at');
    }

    protected function completedCustomerQueueEntriesQuery(string $customerId)
    {
        return QueueEntry::query()
            ->with($this->queueEntryRelations())
            ->where('customer_id', $customerId)
            ->where('queue_status', 'completed')
            ->orderByDesc('updated_at');
    }

    protected function queueEntryRelations(): array
    {
        return [
            'queueSession.branch.company',
            'queueSession.service',
            'appointment.branch.company',
            'appointment.service',
            'appointment.customer.user',
            'walkInTicket.branch.company',
            'walkInTicket.service',
            'walkInTicket.customer.user',
            'customer.user',
        ];
    }
}
