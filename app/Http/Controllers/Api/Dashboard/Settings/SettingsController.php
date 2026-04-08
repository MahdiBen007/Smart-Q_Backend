<?php

namespace App\Http\Controllers\Api\Dashboard\Settings;

use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\Settings\UpdateDashboardSettingsRequest;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Http\Request;

class SettingsController extends DashboardApiController
{
    public function show(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $preference = UserPreference::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            ['dashboard_settings' => $this->defaultSettings()]
        );

        return $this->respond($this->mergeSettings($preference->dashboard_settings));
    }

    public function update(UpdateDashboardSettingsRequest $request)
    {
        /** @var User $user */
        $user = $request->user();

        $preference = UserPreference::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            ['dashboard_settings' => $request->validated()]
        );

        return $this->respond(
            $this->mergeSettings($preference->dashboard_settings),
            'Dashboard settings updated successfully.'
        );
    }

    protected function mergeSettings(?array $settings): array
    {
        return array_replace_recursive($this->defaultSettings(), $settings ?? []);
    }

    protected function defaultSettings(): array
    {
        return [
            'appearance' => [
                'theme' => 'light',
                'language' => 'en',
                'density' => 'comfortable',
                'reducedMotion' => false,
                'surfaceStyle' => 'glass',
            ],
            'workspace' => [
                'sidebarCollapsed' => false,
                'defaultBranchScope' => 'assigned',
                'rememberFilters' => true,
                'stickyDetailsPanel' => true,
                'compactTables' => false,
            ],
            'notifications' => [
                'queueAlerts' => true,
                'serviceAlerts' => true,
                'desktopNotifications' => true,
                'soundEffects' => false,
                'dailySummary' => true,
            ],
            'security' => [
                'maskSensitiveData' => false,
                'confirmDestructiveActions' => true,
                'autoLockEnabled' => true,
                'sessionTimeoutMinutes' => 30,
            ],
        ];
    }
}
