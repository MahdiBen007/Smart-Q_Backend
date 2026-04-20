<?php

namespace App\Http\Controllers\Api\Mobile\Notifications;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class NotificationController extends MobileApiController
{
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $items = Cache::remember(
            $this->notificationsCacheKey($user),
            now()->addSeconds(8),
            function () use ($user): array {
                return Notification::query()
                    ->where('user_id', $user->getKey())
                    ->select([
                        'id',
                        'title',
                        'description',
                        'occurred_at',
                        'created_at',
                        'notification_type',
                        'read_at',
                    ])
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
            }
        );

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
        Cache::forget($this->notificationsCacheKey($user));

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
        Cache::forget($this->notificationsCacheKey($user));

        return $this->respond(message: 'Notification marked as read.');
    }

    public function destroy(Request $request, Notification $notification)
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($notification->user_id === $user->getKey(), 404);

        $notification->delete();
        Cache::forget($this->notificationsCacheKey($user));

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
        Cache::forget($this->notificationsCacheKey($user));

        return $this->respond(message: 'Selected notifications deleted successfully.');
    }

    protected function notificationsCacheKey(User $user): string
    {
        return sprintf('mobile:notifications:%s', $user->getKey());
    }
}
