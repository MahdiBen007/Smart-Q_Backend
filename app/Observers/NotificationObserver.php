<?php

namespace App\Observers;

use App\Models\Notification;
use App\Models\User;
use App\Support\Notifications\FcmPushService;

class NotificationObserver
{
    public function created(Notification $notification): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        $user = User::query()
            ->with('preference')
            ->find($notification->user_id);

        if (! $user) {
            return;
        }

        $mobile = $user->preference?->dashboard_settings['mobile'] ?? [];
        $pushEnabled = (bool) ($mobile['notifications']['push'] ?? true);
        if (! $pushEnabled) {
            return;
        }

        app(FcmPushService::class)->dispatchForNotification($notification);
    }
}

