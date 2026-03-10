<?php

namespace App\Support\Dashboard;

final class DashboardCatalog
{
    public const BRANCH_STATUSES = ['active', 'peak', 'maintenance'];

    public const SERVICE_ICONS = ['wallet', 'home-loan', 'cash', 'support', 'corporate', 'card'];

    public const SERVICE_STATUSES = ['Active', 'Inactive'];

    public const STAFF_ROLES = ['Admin', 'Manager', 'Staff', 'Support'];

    public const STAFF_STATUSES = ['Active', 'Inactive', 'On Leave'];

    public const QUEUE_MONITOR_STATUSES = ['Active', 'Paused', 'Maintenance'];

    public const QUEUE_MONITOR_FILTERS = ['All', 'Serving', 'Next', 'Waiting'];

    public const TICKET_SOURCES = ['reception', 'kiosk', 'qr_scan', 'staff_assisted'];

    public const CHECK_IN_RESULTS = ['success', 'pending', 'manual_assist'];

    public const SETTINGS_THEMES = ['system', 'light', 'dark'];

    public const SETTINGS_LANGUAGES = ['en', 'fr', 'ar'];

    public const SETTINGS_DENSITIES = ['comfortable', 'compact'];

    public const SETTINGS_SURFACE_STYLES = ['glass', 'solid'];

    public const SETTINGS_BRANCH_SCOPES = ['all', 'assigned', 'last-used'];

    public const SETTINGS_SESSION_TIMEOUTS = [15, 30, 45, 60];

    private function __construct() {}
}
