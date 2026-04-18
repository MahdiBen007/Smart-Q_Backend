<?php

namespace App\Http\Controllers\Api\Mobile\Notifications;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NotificationController extends MobileApiController
{
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $items = Notification::query()
            ->where('user_id', $user->getKey())
            ->latest('occurred_at')
            ->limit(50)
            ->get()
            ->map(function (Notification $notification) {
                return [
                    'id' => $notification->getKey(),
                    'title' => $notification->title ?? 'Notification',
                    'message' => $notification->description ?? '',
                    'time' => optional($notification->occurred_at ?? $notification->created_at)
                        ->diffForHumans(),
                    'type' => $notification->notification_type ?? 'system',
                    'unread' => $notification->read_at === null,
                ];
            })
            ->values()
            ->all();

        return $this->respond($items);
    }

    public function markAllRead(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        Notification::query()
            ->where('user_id', $user->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->respond(message: 'Notifications marked as read.');
    }

    public function markRead(Request $request, Notification $notification)
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($notification->user_id === $user->getKey(), 404);

        $notification->update([
            'read_at' => now(),
        ]);

        return $this->respond(message: 'Notification marked as read.');
    }

    public function destroy(Request $request, Notification $notification)
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($notification->user_id === $user->getKey(), 404);

        $notification->delete();

        return $this->respond(message: 'Notification deleted successfully.');
    }

    public function destroyMany(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $ids = collect($validated['ids'] ?? [])
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values()
            ->all();

        if ($ids === []) {
            throw ValidationException::withMessages([
                'ids' => 'No notification ids were provided.',
            ]);
        }

        Notification::query()
            ->where('user_id', $user->getKey())
            ->whereIn('id', $ids)
            ->delete();

        return $this->respond(message: 'Selected notifications deleted successfully.');
    }
}
