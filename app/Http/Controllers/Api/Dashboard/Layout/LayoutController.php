<?php

namespace App\Http\Controllers\Api\Dashboard\Layout;

use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Notification;
use App\Models\User;
use App\Support\Dashboard\DashboardFormatting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class LayoutController extends DashboardApiController
{
    public function topNavbar(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $branchQuery = $this->scopeQueryByCompanyColumn(
            Branch::query()
                ->select(['id', 'branch_name', 'logo_url'])
                ->orderBy('branch_name'),
            $request
        );
        $branchQuery = $this->scopeQueryByAssignedBranchColumn($branchQuery, $request, 'id');

        $branches = $branchQuery
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->getKey(),
                'name' => $branch->branch_name,
                'logoUrl' => $branch->logo_url,
            ])
            ->values();

        if (! $this->shouldRestrictToAssignedBranch($request)) {
            $branches->prepend(['id' => null, 'name' => 'All Branches', 'logoUrl' => null]);
        }

        $defaultBranchId = $user->staffMember?->branch_id;
        if (! is_string($defaultBranchId) || trim($defaultBranchId) === '') {
            $defaultBranchId = null;
        }

        if ($defaultBranchId !== null && ! $branches->contains(fn (array $branch) => (string) ($branch['id'] ?? '') === $defaultBranchId)) {
            $defaultBranchId = null;
        }

        $alerts = $user->notifications()
            ->select([
                'id',
                'user_id',
                'notification_type',
                'title',
                'description',
                'message_content',
                'tone',
                'action_path',
                'occurred_at',
                'created_at',
                'read_at',
            ])
            ->orderByRaw('COALESCE(occurred_at, created_at) DESC')
            ->limit(8)
            ->get()
            ->map(fn (Notification $notification) => $this->transformNotification($notification))
            ->values()
            ->all();

        $bookingsQuery = $this->scopeQueryByCompanyRelation(
            Appointment::query()
                ->select(['id', 'customer_id', 'service_id', 'branch_id', 'appointment_date', 'appointment_time', 'created_at'])
                ->with([
                    'customer:id,full_name',
                    'service:id,service_name',
                    'branch:id,branch_name',
                ])
                ->whereDate('appointment_date', '>=', now()->toDateString())
                ->orderBy('appointment_date')
                ->orderBy('appointment_time')
                ->limit(8),
            $request,
            'branch'
        );
        $bookingsQuery = $this->scopeQueryByAssignedBranchColumn($bookingsQuery, $request);
        $bookingsQuery = $this->scopeQueryByAssignedServiceColumn($bookingsQuery, $request);

        $bookings = $bookingsQuery->get()
            ->map(fn (Appointment $appointment) => $this->transformBooking($appointment))
            ->values()
            ->all();

        return $this->respond([
            'branches' => $branches->values()->all(),
            'alerts' => $alerts,
            'bookings' => $bookings,
            'defaultBranchId' => $defaultBranchId,
        ]);
    }

    public function markNotificationRead(Request $request, Notification $notification)
    {
        abort_unless($notification->user_id === $request->user()->getKey(), 404);

        $notification->update([
            'read_at' => now(),
        ]);

        return $this->respond(
            $this->transformNotification($notification->refresh()),
            'Notification marked as read.'
        );
    }

    public function markAllRead(Request $request)
    {
        $affectedRows = Notification::query()
            ->where('user_id', $request->user()->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->respond(
            ['affected_rows' => $affectedRows],
            'Notifications marked as read.'
        );
    }

    protected function transformNotification(Notification $notification): array
    {
        return [
            'id' => $notification->getKey(),
            'title' => $notification->title ?: DashboardFormatting::titleCase($notification->notification_type),
            'description' => $notification->description ?: $notification->message_content,
            'time' => DashboardFormatting::compactTimeAgo($notification->occurred_at ?? $notification->created_at),
            'unread' => $notification->read_at === null,
            'tone' => $notification->tone,
            'path' => $notification->action_path,
        ];
    }

    protected function transformBooking(Appointment $appointment): array
    {
        return [
            'id' => $appointment->getKey(),
            'name' => $appointment->customer?->full_name ?? 'Unknown Customer',
            'service' => $appointment->service?->service_name ?? 'General Service',
            'branch' => $appointment->branch?->branch_name ?? 'Main Branch',
            'slot' => trim(
                DashboardFormatting::shortDate($appointment->appointment_date).' '.
                DashboardFormatting::shortTime($appointment->appointment_time)
            ),
            'time' => DashboardFormatting::compactTimeAgo($appointment->created_at),
            'isNew' => $appointment->created_at?->diffInHours(now()) <= 24,
        ];
    }
}
