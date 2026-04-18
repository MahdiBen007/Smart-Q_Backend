<?php

namespace App\Support\Notifications;

use App\Models\DeviceToken;
use App\Models\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmPushService
{
    private const FCM_ENDPOINT = 'https://fcm.googleapis.com/fcm/send';

    public function dispatchForNotification(Notification $notification): bool
    {
        $serverKey = trim((string) config('services.fcm.server_key'));
        if ($serverKey === '') {
            return false;
        }

        $tokens = DeviceToken::query()
            ->where('user_id', $notification->user_id)
            ->pluck('fcm_token')
            ->filter(fn ($token) => is_string($token) && trim($token) !== '')
            ->values()
            ->all();

        if ($tokens === []) {
            return false;
        }

        $payload = [
            'registration_ids' => $tokens,
            'priority' => 'high',
            'notification' => [
                'title' => $notification->title ?? 'SmartQdz',
                'body' => $notification->description ?? $notification->message_content ?? '',
                'sound' => 'default',
            ],
            'data' => [
                'notification_id' => (string) $notification->getKey(),
                'type' => (string) ($notification->notification_type ?? 'system'),
                'action_path' => (string) ($notification->action_path ?? '/notification-center'),
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'key='.$serverKey,
                'Content-Type' => 'application/json',
            ])->timeout(10)->post(self::FCM_ENDPOINT, $payload);
        } catch (\Throwable $exception) {
            Log::warning('FCM push request failed.', [
                'notification_id' => $notification->getKey(),
                'user_id' => $notification->user_id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('FCM push response was not successful.', [
                'notification_id' => $notification->getKey(),
                'user_id' => $notification->user_id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            return false;
        }

        $results = $decoded['results'] ?? null;
        if (! is_array($results)) {
            return ((int) ($decoded['success'] ?? 0)) > 0;
        }

        $invalidTokens = [];
        $successCount = 0;
        foreach ($results as $index => $result) {
            if (! is_array($result)) {
                continue;
            }

            if (isset($result['message_id'])) {
                $successCount++;
                continue;
            }

            $error = (string) ($result['error'] ?? '');
            if (in_array($error, ['NotRegistered', 'InvalidRegistration'], true)) {
                $token = $tokens[$index] ?? null;
                if (is_string($token) && trim($token) !== '') {
                    $invalidTokens[] = $token;
                }
            }
        }

        if ($invalidTokens !== []) {
            DeviceToken::query()
                ->whereIn('fcm_token', array_values(array_unique($invalidTokens)))
                ->delete();
        }

        return $successCount > 0;
    }
}

