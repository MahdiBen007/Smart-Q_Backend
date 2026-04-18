<?php

namespace App\Http\Controllers\Api\Mobile\Preferences;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Http\Request;

class PreferenceController extends MobileApiController
{
    public function show(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $preference = $user->preference?->dashboard_settings ?? [];
        $mobile = $preference['mobile'] ?? [];

        return $this->respond([
            'language' => $mobile['language'] ?? 'en',
            'notifications' => $mobile['notifications'] ?? [
                'push' => true,
                'sms' => false,
                'email' => true,
            ],
        ]);
    }

    public function update(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $preference = $user->preference ?? UserPreference::query()->create([
            'user_id' => $user->getKey(),
            'dashboard_settings' => [],
        ]);

        $settings = $preference->dashboard_settings ?? [];
        $mobile = $settings['mobile'] ?? [];

        if ($request->has('language')) {
            $mobile['language'] = $request->string('language')->value();
        }

        if (is_array($request->input('notifications'))) {
            $mobile['notifications'] = array_merge(
                $mobile['notifications'] ?? [],
                $request->input('notifications')
            );
        }

        $settings['mobile'] = $mobile;
        $preference->update(['dashboard_settings' => $settings]);

        return $this->respond([
            'language' => $mobile['language'] ?? 'en',
            'notifications' => $mobile['notifications'] ?? [
                'push' => true,
                'sms' => false,
                'email' => true,
            ],
        ], 'Preferences updated successfully.');
    }
}
