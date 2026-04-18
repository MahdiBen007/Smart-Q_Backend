<?php

namespace App\Http\Controllers\Api\Mobile\Notifications;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceTokenController extends MobileApiController
{
    public function store(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', Rule::in(['android', 'ios', 'web'])],
            'app_version' => ['nullable', 'string', 'max:64'],
        ]);

        $token = trim((string) ($validated['token'] ?? ''));
        if ($token === '') {
            return $this->respondValidationError('Device token is required.', [
                'token' => ['Device token is required.'],
            ]);
        }

        DeviceToken::query()->updateOrCreate(
            ['fcm_token' => $token],
            [
                'user_id' => $user->getKey(),
                'platform' => (string) ($validated['platform'] ?? 'android'),
                'app_version' => (string) ($validated['app_version'] ?? ''),
                'last_seen_at' => now(),
            ],
        );

        return $this->respond(message: 'Device token registered successfully.');
    }

    public function destroy(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $token = trim((string) ($validated['token'] ?? ''));
        if ($token === '') {
            return $this->respondValidationError('Device token is required.', [
                'token' => ['Device token is required.'],
            ]);
        }

        DeviceToken::query()
            ->where('user_id', $user->getKey())
            ->where('fcm_token', $token)
            ->delete();

        return $this->respond(message: 'Device token removed successfully.');
    }
}

