<?php

namespace App\Http\Requests\Api\Dashboard\Settings;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;
use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class UpdateDashboardSettingsRequest extends DashboardFormRequest
{
    public function rules(): array
    {
        return [
            'appearance.theme' => ['required', Rule::in(DashboardCatalog::SETTINGS_THEMES)],
            'appearance.language' => ['required', Rule::in(DashboardCatalog::SETTINGS_LANGUAGES)],
            'appearance.density' => ['required', Rule::in(DashboardCatalog::SETTINGS_DENSITIES)],
            'appearance.reducedMotion' => ['required', 'boolean'],
            'appearance.surfaceStyle' => ['required', Rule::in(DashboardCatalog::SETTINGS_SURFACE_STYLES)],
            'workspace.sidebarCollapsed' => ['required', 'boolean'],
            'workspace.defaultBranchScope' => ['required', Rule::in(DashboardCatalog::SETTINGS_BRANCH_SCOPES)],
            'workspace.rememberFilters' => ['required', 'boolean'],
            'workspace.stickyDetailsPanel' => ['required', 'boolean'],
            'workspace.compactTables' => ['required', 'boolean'],
            'notifications.queueAlerts' => ['required', 'boolean'],
            'notifications.serviceAlerts' => ['required', 'boolean'],
            'notifications.desktopNotifications' => ['required', 'boolean'],
            'notifications.soundEffects' => ['required', 'boolean'],
            'notifications.dailySummary' => ['required', 'boolean'],
            'security.maskSensitiveData' => ['required', 'boolean'],
            'security.confirmDestructiveActions' => ['required', 'boolean'],
            'security.autoLockEnabled' => ['required', 'boolean'],
            'security.sessionTimeoutMinutes' => ['required', Rule::in(DashboardCatalog::SETTINGS_SESSION_TIMEOUTS)],
        ];
    }

    public function attributes(): array
    {
        return [
            'appearance.theme' => 'appearance theme',
            'appearance.language' => 'appearance language',
            'appearance.density' => 'appearance density',
            'appearance.reducedMotion' => 'reduced motion setting',
            'appearance.surfaceStyle' => 'surface style',
            'workspace.sidebarCollapsed' => 'sidebar collapsed setting',
            'workspace.defaultBranchScope' => 'default branch scope',
            'workspace.rememberFilters' => 'remember filters setting',
            'workspace.stickyDetailsPanel' => 'sticky details panel setting',
            'workspace.compactTables' => 'compact tables setting',
            'notifications.queueAlerts' => 'queue alerts setting',
            'notifications.serviceAlerts' => 'service alerts setting',
            'notifications.desktopNotifications' => 'desktop notifications setting',
            'notifications.soundEffects' => 'sound effects setting',
            'notifications.dailySummary' => 'daily summary setting',
            'security.maskSensitiveData' => 'mask sensitive data setting',
            'security.confirmDestructiveActions' => 'confirm destructive actions setting',
            'security.autoLockEnabled' => 'auto lock setting',
            'security.sessionTimeoutMinutes' => 'session timeout',
        ];
    }
}
