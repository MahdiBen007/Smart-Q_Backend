<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Enums\EmploymentStatus;
use App\Enums\UserRoleName;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_user_can_login_and_fetch_profile(): void
    {
        [$user] = $this->createDashboardContext();

        $loginResponse = $this->postJson('/api/dashboard/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', $user->email);

        $token = $loginResponse->json('data.access_token');

        $this->withToken($token)
            ->getJson('/api/dashboard/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.staff_member.full_name', 'Dashboard Admin');
    }

    public function test_dashboard_user_can_update_dashboard_settings(): void
    {
        [$user] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $payload = [
            'appearance' => [
                'theme' => 'dark',
                'language' => 'fr',
                'density' => 'compact',
                'reducedMotion' => true,
                'surfaceStyle' => 'solid',
            ],
            'workspace' => [
                'sidebarCollapsed' => true,
                'defaultBranchScope' => 'all',
                'rememberFilters' => false,
                'stickyDetailsPanel' => true,
                'compactTables' => true,
            ],
            'notifications' => [
                'queueAlerts' => true,
                'serviceAlerts' => true,
                'desktopNotifications' => false,
                'soundEffects' => true,
                'dailySummary' => false,
            ],
            'security' => [
                'maskSensitiveData' => true,
                'confirmDestructiveActions' => true,
                'autoLockEnabled' => false,
                'sessionTimeoutMinutes' => 45,
            ],
        ];

        $this->withToken($token)
            ->putJson('/api/dashboard/settings/dashboard', $payload)
            ->assertOk()
            ->assertJsonPath('data.appearance.theme', 'dark')
            ->assertJsonPath('data.security.sessionTimeoutMinutes', 45);
    }

    public function test_dashboard_user_can_create_service_and_fetch_bootstrap(): void
    {
        [$user, $branch] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $this->withToken($token)
            ->postJson('/api/dashboard/services', [
                'name' => 'Premium Consultation',
                'service_code' => 'SRV-101',
                'subtitle' => 'High value advisory',
                'description' => 'Dedicated consultation flow for premium customers.',
                'icon' => 'support',
                'average_service_duration_minutes' => 20,
                'status' => 'Active',
                'branch_ids' => [$branch->getKey()],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Premium Consultation');

        $this->withToken($token)
            ->getJson('/api/dashboard/services/bootstrap')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Premium Consultation']);
    }

    public function test_dashboard_user_can_register_walk_in_and_see_it_in_queue_monitor(): void
    {
        [$user, $branch, $service] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $this->withToken($token)
            ->postJson('/api/dashboard/walk-ins', [
                'branch_id' => $branch->getKey(),
                'service_id' => $service->getKey(),
                'customer_name' => 'Walk In Customer',
                'phone_number' => '+213500000999',
                'email_address' => 'walkin@example.test',
                'ticket_source' => 'reception',
            ])
            ->assertCreated()
            ->assertJsonPath('data.customer.fullName', 'Walk In Customer');

        $this->withToken($token)
            ->getJson('/api/dashboard/queue-monitor/bootstrap')
            ->assertOk()
            ->assertJsonCount(1, 'data.queueEntries')
            ->assertJsonPath('data.queueEntries.0.customer', 'Walk In Customer');
    }

    public function test_service_creation_returns_structured_validation_errors(): void
    {
        [$user] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $this->withToken($token)
            ->postJson('/api/dashboard/services', [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The service name field is required.')
            ->assertJsonPath('errors.name.0', 'The service name field is required.')
            ->assertJsonPath('errors.service_code.0', 'The service code field is required.');
    }

    public function test_queue_monitor_reset_requires_session_reference_with_consistent_message(): void
    {
        [$user] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $this->withToken($token)
            ->postJson('/api/dashboard/queue-monitor/reset', [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Provide queue_session_id or both branch_id and service_id.')
            ->assertJsonPath('errors.queue_session_id.0', 'Provide queue_session_id or both branch_id and service_id.');
    }

    protected function loginAndReturnToken(User $user): string
    {
        $response = $this->postJson('/api/dashboard/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        return $response->json('data.access_token');
    }

    protected function createDashboardContext(): array
    {
        $company = Company::query()->create([
            'company_name' => 'SmartQdz',
            'company_status' => CompanyStatus::Active,
        ]);

        $branch = Branch::query()->create([
            'company_id' => $company->getKey(),
            'branch_name' => 'Downtown HQ',
            'branch_address' => 'Business District',
            'branch_code' => 'HQ-001',
            'branch_status' => 'active',
        ]);

        $service = Service::query()->create([
            'branch_id' => $branch->getKey(),
            'service_name' => 'General Inquiry',
            'average_service_duration_minutes' => 10,
            'is_active' => true,
            'service_code' => 'SRV-001',
            'service_subtitle' => 'General desk',
            'service_description' => 'Default support queue for standard operations.',
            'service_icon' => 'support',
        ]);
        $service->branches()->sync([$branch->getKey()]);

        $user = User::query()->create([
            'email' => 'admin@example.test',
            'phone_number' => '+213500000001',
            'password_hash' => 'password123',
            'is_active' => true,
        ]);

        UserRole::query()->create([
            'user_id' => $user->getKey(),
            'role_name' => UserRoleName::Admin,
        ]);

        StaffMember::query()->create([
            'user_id' => $user->getKey(),
            'company_id' => $company->getKey(),
            'branch_id' => $branch->getKey(),
            'full_name' => 'Dashboard Admin',
            'display_staff_code' => 'STF-00001',
            'employment_status' => EmploymentStatus::Active,
            'is_online' => true,
            'last_active_at' => now(),
        ]);

        return [$user, $branch, $service];
    }
}
