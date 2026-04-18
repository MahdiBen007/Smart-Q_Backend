<?php

namespace Tests\Feature;

use App\Enums\CheckInResult;
use App\Enums\AppointmentStatus;
use App\Enums\CompanyStatus;
use App\Enums\EmploymentStatus;
use App\Enums\QueueEntryStatus;
use App\Enums\QueueSessionStatus;
use App\Enums\TokenStatus;
use App\Enums\UserRoleName;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CheckInRecord;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DailyQueueSession;
use App\Models\KioskDevice;
use App\Models\QrCodeToken;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Dashboard\BookingCodeFormatter;
use App\Support\Dashboard\OperationalWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

        $token = $this->extractAuthTokenFromResponse($loginResponse);

        $loginResponse
            ->assertOk()
            ->assertCookie((string) config('jwt.cookie_name'))
            ->assertJsonPath('data.user.email', $user->email);

        $this->withToken($token)
            ->getJson('/api/dashboard/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.staff_member.full_name', 'Dashboard Admin');
    }

    public function test_disabled_dashboard_user_sees_disabled_account_message_on_login(): void
    {
        [$user, , , $staff] = $this->createDashboardContext();

        $user->update([
            'is_active' => false,
        ]);

        $staff->update([
            'employment_status' => EmploymentStatus::Inactive,
        ]);

        $this->postJson('/api/dashboard/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'This account is disabled. Please contact your administrator.');
    }

    public function test_dashboard_user_can_update_profile_details(): void
    {
        [$user] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $this->withToken($token)
            ->patchJson('/api/dashboard/auth/me', [
                'full_name' => 'Updated Dashboard Admin',
                'email' => 'updated.dashboard.admin@example.test',
                'phone_number' => '+213555000321',
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'updated.dashboard.admin@example.test')
            ->assertJsonPath('data.phone_number', '+213555000321')
            ->assertJsonPath('data.staff_member.full_name', 'Updated Dashboard Admin');

        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'email' => 'updated.dashboard.admin@example.test',
            'phone_number' => '+213555000321',
        ]);

        $this->assertDatabaseHas('staff_members', [
            'user_id' => $user->getKey(),
            'full_name' => 'Updated Dashboard Admin',
        ]);
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

    public function test_dashboard_settings_are_isolated_per_user(): void
    {
        [$firstUser] = $this->createDashboardContext([
            'user_email' => 'first.settings@example.test',
            'staff_name' => 'First Settings User',
        ]);
        [$secondUser] = $this->createDashboardContext([
            'user_email' => 'second.settings@example.test',
            'staff_name' => 'Second Settings User',
        ]);

        $firstToken = $this->loginAndReturnToken($firstUser);
        $secondToken = $this->loginAndReturnToken($secondUser);

        $firstPayload = [
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
                'stickyDetailsPanel' => false,
                'compactTables' => true,
            ],
            'notifications' => [
                'queueAlerts' => false,
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

        $secondPayload = [
            'appearance' => [
                'theme' => 'light',
                'language' => 'ar',
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
                'serviceAlerts' => false,
                'desktopNotifications' => true,
                'soundEffects' => false,
                'dailySummary' => true,
            ],
            'security' => [
                'maskSensitiveData' => false,
                'confirmDestructiveActions' => false,
                'autoLockEnabled' => true,
                'sessionTimeoutMinutes' => 30,
            ],
        ];

        $this->withToken($firstToken)
            ->putJson('/api/dashboard/settings/dashboard', $firstPayload)
            ->assertOk()
            ->assertJsonPath('data.appearance.theme', 'dark')
            ->assertJsonPath('data.appearance.language', 'fr');

        $this->withToken($secondToken)
            ->putJson('/api/dashboard/settings/dashboard', $secondPayload)
            ->assertOk()
            ->assertJsonPath('data.appearance.theme', 'light')
            ->assertJsonPath('data.appearance.language', 'ar');

        $this->withToken($firstToken)
            ->getJson('/api/dashboard/settings/dashboard')
            ->assertOk()
            ->assertJsonPath('data.appearance.theme', 'dark')
            ->assertJsonPath('data.appearance.language', 'fr')
            ->assertJsonPath('data.workspace.sidebarCollapsed', true)
            ->assertJsonPath('data.security.sessionTimeoutMinutes', 45);

        $this->withToken($secondToken)
            ->getJson('/api/dashboard/settings/dashboard')
            ->assertOk()
            ->assertJsonPath('data.appearance.theme', 'light')
            ->assertJsonPath('data.appearance.language', 'ar')
            ->assertJsonPath('data.workspace.sidebarCollapsed', false)
            ->assertJsonPath('data.security.sessionTimeoutMinutes', 30);
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

    public function test_queue_monitor_bootstrap_returns_appointment_customer_name(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $appointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Queue Monitor Appointment Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '11:15:00',
        );

        $this->createQueueEntryForAppointment($appointment, 1, QueueEntryStatus::Serving);

        $this->withToken($token)
            ->getJson('/api/dashboard/queue-monitor/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.queueEntries.0.customer', 'Queue Monitor Appointment Customer');
    }

    public function test_regular_appointment_is_visible_in_queue_monitor_before_check_in(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Regular Queue Customer',
            status: AppointmentStatus::Confirmed,
            appointmentDate: now()->toDateString(),
            appointmentTime: '11:00:00',
            customerUserType: 'regular',
        );

        $this->withToken($token)
            ->getJson('/api/dashboard/queue-monitor/bootstrap')
            ->assertOk()
            ->assertJsonCount(1, 'data.queueEntries')
            ->assertJsonPath('data.queueEntries.0.customer', 'Regular Queue Customer')
            ->assertJsonPath('data.queueEntries.0.checkIn', 'Not Arrived');
    }

    public function test_queue_monitor_bootstrap_exposes_server_grace_countdown_for_awaiting_check_in_entry(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $appointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Countdown Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '10:30:00',
        );

        $session = DailyQueueSession::query()->firstOrCreate(
            [
                'branch_id' => $branch->getKey(),
                'service_id' => $service->getKey(),
                'session_date' => now()->toDateString(),
            ],
            [
                'session_start_time' => '08:00:00',
                'session_end_time' => '17:00:00',
                'session_status' => QueueSessionStatus::Live,
            ]
        );

        QueueEntry::query()->create([
            'queue_session_id' => $session->getKey(),
            'customer_id' => $appointment->customer_id,
            'queue_position' => 1,
            'queue_status' => QueueEntryStatus::Serving,
            'checked_in_at' => null,
            'service_started_at' => now()->subSeconds(12),
            'appointment_id' => $appointment->getKey(),
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/dashboard/queue-monitor/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.queueEntries.0.awaitingCheckIn', true);

        $remainingSeconds = (int) ($response->json('data.queueEntries.0.checkInGraceRemainingSeconds') ?? -1);

        $this->assertGreaterThanOrEqual(10, $remainingSeconds);
        $this->assertLessThanOrEqual(20, $remainingSeconds);
    }

    public function test_appointment_qr_check_in_creates_queue_entry_and_consumes_token(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $appointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'QR Appointment Customer',
            status: AppointmentStatus::Confirmed,
            appointmentDate: now()->toDateString(),
            appointmentTime: '10:00:00',
        );
        $qrToken = $this->createAppointmentQrToken($appointment);

        $this->withToken($token)
            ->postJson('/api/dashboard/appointments/check-in', [
                'token_value' => $qrToken->token_value,
                'result' => CheckInResult::Success->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.appointment.customerName', 'QR Appointment Customer')
            ->assertJsonPath('data.appointment.queueState', 'Checked In')
            ->assertJsonPath('data.appointment.status', 'Active')
            ->assertJsonPath('data.tokenStatus', 'Consumed');

        $this->assertDatabaseHas('queue_entries', [
            'appointment_id' => $appointment->getKey(),
            'queue_status' => QueueEntryStatus::Next->value,
        ]);
        $this->assertDatabaseHas('qr_code_tokens', [
            'id' => $qrToken->getKey(),
            'token_status' => TokenStatus::Consumed->value,
        ]);
        $this->assertDatabaseHas('check_in_records', [
            'qr_token_id' => $qrToken->getKey(),
            'customer_id' => $appointment->customer_id,
            'check_in_result' => CheckInResult::Success->value,
        ]);
    }

    public function test_special_needs_appointment_is_not_auto_enqueued_before_check_in(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Special Needs Customer',
            status: AppointmentStatus::Confirmed,
            appointmentDate: now()->toDateString(),
            appointmentTime: '10:00:00',
            customerUserType: 'special_needs',
        );

        $this->withToken($token)
            ->getJson('/api/dashboard/queue-monitor/bootstrap')
            ->assertOk()
            ->assertJsonCount(0, 'data.queueEntries');
    }

    public function test_special_needs_entry_is_hidden_in_queue_monitor_until_check_in(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $specialAppointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Special Hidden Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '10:00:00',
            customerUserType: 'special_needs',
        );

        $session = DailyQueueSession::query()->firstOrCreate(
            [
                'branch_id' => $branch->getKey(),
                'service_id' => $service->getKey(),
                'session_date' => now()->toDateString(),
            ],
            [
                'session_start_time' => '08:00:00',
                'session_end_time' => '17:00:00',
                'session_status' => QueueSessionStatus::Live,
            ]
        );

        QueueEntry::query()->create([
            'queue_session_id' => $session->getKey(),
            'customer_id' => $specialAppointment->customer_id,
            'queue_position' => 1,
            'queue_status' => QueueEntryStatus::Waiting,
            'checked_in_at' => null,
            'appointment_id' => $specialAppointment->getKey(),
        ]);

        $this->withToken($token)
            ->getJson('/api/dashboard/queue-monitor/bootstrap')
            ->assertOk()
            ->assertJsonCount(0, 'data.queueEntries');
    }

    public function test_special_needs_appointment_code_is_consistent_in_appointments_list(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Special Needs Appointment List',
            status: AppointmentStatus::Confirmed,
            appointmentDate: now()->toDateString(),
            appointmentTime: '11:00:00',
            customerUserType: 'special_needs',
        );

        $response = $this->withToken($token)
            ->getJson('/api/dashboard/appointments')
            ->assertOk();

        $displayId = $response->json('data.appointments.0.displayId');
        $this->assertTrue(
            is_string($displayId) && str_starts_with($displayId, 'SN-'),
            'Expected appointment display code to start with SN- in appointments list.'
        );
    }

    public function test_regular_appointment_code_does_not_use_special_needs_prefix(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Regular Customer Appointment List',
            status: AppointmentStatus::Confirmed,
            appointmentDate: now()->toDateString(),
            appointmentTime: '11:30:00',
            customerUserType: 'regular',
        );

        $response = $this->withToken($token)
            ->getJson('/api/dashboard/appointments')
            ->assertOk();

        $displayId = $response->json('data.appointments.0.displayId');
        $this->assertTrue(
            is_string($displayId) && ! str_starts_with($displayId, 'SN-'),
            'Regular user booking code must not start with SN-.'
        );
    }

    public function test_special_needs_check_in_gets_queue_priority_and_special_code(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $servingAppointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Serving Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '09:00:00',
        );
        $session = DailyQueueSession::query()->firstOrCreate(
            [
                'branch_id' => $branch->getKey(),
                'service_id' => $service->getKey(),
                'session_date' => now()->toDateString(),
            ],
            [
                'session_start_time' => '08:00:00',
                'session_end_time' => '17:00:00',
                'session_status' => QueueSessionStatus::Live,
            ]
        );

        $servingEntry = QueueEntry::query()->create([
            'queue_session_id' => $session->getKey(),
            'customer_id' => $servingAppointment->customer_id,
            'queue_position' => 1,
            'queue_status' => QueueEntryStatus::Serving,
            'checked_in_at' => now(),
            'appointment_id' => $servingAppointment->getKey(),
        ]);

        $waitingAppointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Waiting Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '09:15:00',
        );
        $waitingEntry = QueueEntry::query()->create([
            'queue_session_id' => $session->getKey(),
            'customer_id' => $waitingAppointment->customer_id,
            'queue_position' => 2,
            'queue_status' => QueueEntryStatus::Waiting,
            'checked_in_at' => now(),
            'appointment_id' => $waitingAppointment->getKey(),
        ]);

        $specialAppointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Priority Customer',
            status: AppointmentStatus::Confirmed,
            appointmentDate: now()->toDateString(),
            appointmentTime: '10:00:00',
            customerUserType: 'special_needs',
        );
        $specialToken = $this->createAppointmentQrToken($specialAppointment);

        $this->withToken($token)
            ->postJson('/api/dashboard/appointments/check-in', [
                'token_value' => $specialToken->token_value,
                'result' => CheckInResult::Success->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.appointment.queueState', 'Checked In');

        $specialEntry = QueueEntry::query()
            ->where('appointment_id', $specialAppointment->getKey())
            ->firstOrFail();

        $this->assertSame(2, (int) $specialEntry->queue_position);
        $this->assertSame(QueueEntryStatus::Next->value, $specialEntry->queue_status->value);

        $waitingEntry->refresh();
        $servingEntry->refresh();

        $this->assertSame(1, (int) $servingEntry->queue_position);
        $this->assertSame(3, (int) $waitingEntry->queue_position);

        $response = $this->withToken($token)
            ->getJson('/api/dashboard/queue-monitor/bootstrap')
            ->assertOk();

        $queueEntries = $response->json('data.queueEntries');
        $this->assertSame('Priority Customer', $queueEntries[0]['customer'] ?? null);
        $priorityCode = $queueEntries[0]['ticketId'] ?? '';
        $this->assertTrue(
            is_string($priorityCode) && str_starts_with($priorityCode, 'SN-'),
            'Expected special-needs check-in ticket code to start with SN-.'
        );
    }

    public function test_mobile_queue_status_selects_lowest_position_serving_entry(): void
    {
        [, $branch, $service] = $this->createDashboardContext();
        [$mobileUser, $mobileCustomer] = $this->createMobileCustomerContext();
        $token = $this->loginMobileAndReturnToken($mobileUser);

        $otherCustomer = Customer::query()->create([
            'full_name' => 'Other Serving Customer',
            'phone_number' => '+2135'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'email_address' => 'other.serving@example.test',
        ]);

        $session = DailyQueueSession::query()->create([
            'branch_id' => $branch->getKey(),
            'service_id' => $service->getKey(),
            'session_date' => now()->toDateString(),
            'session_start_time' => '08:00:00',
            'session_end_time' => '17:00:00',
            'session_status' => QueueSessionStatus::Live,
        ]);

        $myAppointment = Appointment::query()->create([
            'customer_id' => $mobileCustomer->getKey(),
            'branch_id' => $branch->getKey(),
            'service_id' => $service->getKey(),
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '09:00:00',
            'appointment_status' => AppointmentStatus::Active,
        ]);

        $myEntry = QueueEntry::query()->create([
            'queue_session_id' => $session->getKey(),
            'customer_id' => $mobileCustomer->getKey(),
            'queue_position' => 1,
            'queue_status' => QueueEntryStatus::Serving,
            'checked_in_at' => now(),
            'appointment_id' => $myAppointment->getKey(),
        ]);

        $otherAppointment = Appointment::query()->create([
            'customer_id' => $otherCustomer->getKey(),
            'branch_id' => $branch->getKey(),
            'service_id' => $service->getKey(),
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '09:15:00',
            'appointment_status' => AppointmentStatus::Active,
        ]);

        QueueEntry::query()->create([
            'queue_session_id' => $session->getKey(),
            'customer_id' => $otherCustomer->getKey(),
            'queue_position' => 2,
            'queue_status' => QueueEntryStatus::Serving,
            'checked_in_at' => now(),
            'appointment_id' => $otherAppointment->getKey(),
        ]);

        $expectedCode = BookingCodeFormatter::queueEntryDisplayCode(
            $myEntry->fresh([
                'queueSession.branch.company',
                'queueSession.service',
                'appointment.customer.user',
                'appointment.branch.company',
                'appointment.service',
                'customer.user',
            ])
        );

        $this->withToken($token)
            ->getJson('/api/mobile/queue/status')
            ->assertOk()
            ->assertJsonPath('data.user_ticket', $expectedCode)
            ->assertJsonPath('data.currently_serving', $expectedCode);
    }

    public function test_mobile_queue_status_prefers_today_queue_entry_over_stale_previous_day_entry(): void
    {
        [, $branch, $service] = $this->createDashboardContext();
        [$mobileUser, $mobileCustomer] = $this->createMobileCustomerContext();
        $token = $this->loginMobileAndReturnToken($mobileUser);

        $yesterdaySession = DailyQueueSession::query()->create([
            'branch_id' => $branch->getKey(),
            'service_id' => $service->getKey(),
            'session_date' => now()->subDay()->toDateString(),
            'session_start_time' => '08:00:00',
            'session_end_time' => '17:00:00',
            'session_status' => QueueSessionStatus::Live,
        ]);

        $yesterdayAppointment = Appointment::query()->create([
            'customer_id' => $mobileCustomer->getKey(),
            'branch_id' => $branch->getKey(),
            'service_id' => $service->getKey(),
            'appointment_date' => now()->subDay()->toDateString(),
            'appointment_time' => '10:00:00',
            'appointment_status' => AppointmentStatus::Active,
        ]);

        $staleEntry = QueueEntry::query()->create([
            'queue_session_id' => $yesterdaySession->getKey(),
            'customer_id' => $mobileCustomer->getKey(),
            'queue_position' => 1,
            'queue_status' => QueueEntryStatus::Waiting,
            'appointment_id' => $yesterdayAppointment->getKey(),
        ]);

        $staleEntry->forceFill([
            'updated_at' => now()->addMinute(),
        ])->save();

        $todaySession = DailyQueueSession::query()->create([
            'branch_id' => $branch->getKey(),
            'service_id' => $service->getKey(),
            'session_date' => now()->toDateString(),
            'session_start_time' => '08:00:00',
            'session_end_time' => '17:00:00',
            'session_status' => QueueSessionStatus::Live,
        ]);

        $todayAppointment = Appointment::query()->create([
            'customer_id' => $mobileCustomer->getKey(),
            'branch_id' => $branch->getKey(),
            'service_id' => $service->getKey(),
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '11:00:00',
            'appointment_status' => AppointmentStatus::Active,
        ]);

        $todayEntry = QueueEntry::query()->create([
            'queue_session_id' => $todaySession->getKey(),
            'customer_id' => $mobileCustomer->getKey(),
            'queue_position' => 2,
            'queue_status' => QueueEntryStatus::Waiting,
            'appointment_id' => $todayAppointment->getKey(),
        ]);

        $expectedTodayCode = BookingCodeFormatter::queueEntryDisplayCode(
            $todayEntry->fresh([
                'queueSession.branch.company',
                'queueSession.service',
                'appointment.customer.user',
                'appointment.branch.company',
                'appointment.service',
                'customer.user',
            ])
        );

        $this->withToken($token)
            ->getJson('/api/mobile/queue/status')
            ->assertOk()
            ->assertJsonPath('data.user_ticket', $expectedTodayCode);
    }

    public function test_queue_monitor_bootstrap_only_returns_today_entries_when_today_is_empty(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $appointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Latest Snapshot Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->subDay()->toDateString(),
            appointmentTime: '10:00:00',
        );

        $this->createQueueEntryForAppointment($appointment, 1, QueueEntryStatus::Serving);

        $this->withToken($token)
            ->getJson('/api/dashboard/queue-monitor/bootstrap')
            ->assertOk()
            ->assertJsonCount(0, 'data.queueEntries');
    }

    public function test_queue_monitor_bootstrap_prunes_orphaned_active_queue_entries(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $appointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Orphan Entry Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '10:30:00',
        );

        $entry = $this->createQueueEntryForAppointment($appointment, 1, QueueEntryStatus::Waiting);
        $appointment->delete();

        $this->withToken($token)
            ->getJson('/api/dashboard/queue-monitor/bootstrap')
            ->assertOk()
            ->assertJsonCount(0, 'data.queueEntries');

        $this->assertDatabaseHas('queue_entries', [
            'id' => $entry->getKey(),
            'queue_status' => QueueEntryStatus::Cancelled->value,
        ]);
    }

    public function test_queue_monitor_bootstrap_keeps_past_day_checked_in_appointments_uncancelled(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $appointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Past Day Checked-In Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->subDay()->toDateString(),
            appointmentTime: '10:00:00',
        );

        $entry = $this->createQueueEntryForAppointment($appointment, 1, QueueEntryStatus::Serving);

        $this->withToken($token)
            ->getJson('/api/dashboard/queue-monitor/bootstrap')
            ->assertOk();

        $this->assertDatabaseMissing('appointments', [
            'id' => $appointment->getKey(),
            'appointment_status' => AppointmentStatus::Cancelled->value,
        ]);
        $this->assertDatabaseHas('queue_entries', [
            'id' => $entry->getKey(),
            'queue_status' => QueueEntryStatus::Cancelled->value,
        ]);
    }

    public function test_skipping_unchecked_in_serving_appointment_requeues_without_cancelling_booking(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $appointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Late Arrival Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '10:00:00',
        );

        $qrToken = $this->createAppointmentQrToken($appointment);

        $session = DailyQueueSession::query()->firstOrCreate(
            [
                'branch_id' => $branch->getKey(),
                'service_id' => $service->getKey(),
                'session_date' => now()->toDateString(),
            ],
            [
                'session_start_time' => '08:00:00',
                'session_end_time' => '17:00:00',
                'session_status' => QueueSessionStatus::Live,
            ]
        );

        $entry = QueueEntry::query()->create([
            'queue_session_id' => $session->getKey(),
            'customer_id' => $appointment->customer_id,
            'queue_position' => 1,
            'queue_status' => QueueEntryStatus::Serving,
            'checked_in_at' => null,
            'service_started_at' => now()->subSeconds(6),
            'appointment_id' => $appointment->getKey(),
        ]);

        $followerAppointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Follower Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '10:15:00',
        );

        QueueEntry::query()->create([
            'queue_session_id' => $session->getKey(),
            'customer_id' => $followerAppointment->customer_id,
            'queue_position' => 2,
            'queue_status' => QueueEntryStatus::Waiting,
            'checked_in_at' => now(),
            'service_started_at' => null,
            'appointment_id' => $followerAppointment->getKey(),
        ]);

        $this->withToken($token)
            ->patchJson('/api/dashboard/queue-monitor/entries/'.$entry->getKey().'/skip')
            ->assertOk();

        $this->assertDatabaseMissing('queue_entries', [
            'id' => $entry->getKey(),
            'queue_status' => QueueEntryStatus::Cancelled->value,
        ]);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->getKey(),
            'appointment_status' => AppointmentStatus::Active->value,
        ]);
        $this->assertDatabaseHas('qr_code_tokens', [
            'id' => $qrToken->getKey(),
            'token_status' => TokenStatus::Active->value,
        ]);

        $updatedEntry = QueueEntry::query()->findOrFail($entry->getKey());
        $this->assertNotSame(QueueEntryStatus::Cancelled->value, $updatedEntry->queue_status->value);
        $this->assertSame(2, (int) $updatedEntry->queue_position);
    }

    public function test_skipping_unchecked_in_next_appointment_does_not_cancel_booking(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $session = DailyQueueSession::query()->firstOrCreate(
            [
                'branch_id' => $branch->getKey(),
                'service_id' => $service->getKey(),
                'session_date' => now()->toDateString(),
            ],
            [
                'session_start_time' => '08:00:00',
                'session_end_time' => '17:00:00',
                'session_status' => QueueSessionStatus::Live,
            ]
        );

        $servingAppointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Serving Base Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '09:00:00',
        );

        QueueEntry::query()->create([
            'queue_session_id' => $session->getKey(),
            'customer_id' => $servingAppointment->customer_id,
            'queue_position' => 1,
            'queue_status' => QueueEntryStatus::Serving,
            'checked_in_at' => now(),
            'service_started_at' => now()->subMinute(),
            'appointment_id' => $servingAppointment->getKey(),
        ]);

        $nextAppointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Next Not Arrived Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '09:15:00',
        );
        $nextToken = $this->createAppointmentQrToken($nextAppointment);

        $nextEntry = QueueEntry::query()->create([
            'queue_session_id' => $session->getKey(),
            'customer_id' => $nextAppointment->customer_id,
            'queue_position' => 2,
            'queue_status' => QueueEntryStatus::Next,
            'checked_in_at' => null,
            'service_started_at' => null,
            'appointment_id' => $nextAppointment->getKey(),
        ]);

        $this->withToken($token)
            ->patchJson('/api/dashboard/queue-monitor/entries/'.$nextEntry->getKey().'/skip')
            ->assertOk();

        $this->assertDatabaseMissing('queue_entries', [
            'id' => $nextEntry->getKey(),
            'queue_status' => QueueEntryStatus::Cancelled->value,
        ]);
        $this->assertDatabaseHas('appointments', [
            'id' => $nextAppointment->getKey(),
            'appointment_status' => AppointmentStatus::Active->value,
        ]);
        $this->assertDatabaseHas('qr_code_tokens', [
            'id' => $nextToken->getKey(),
            'token_status' => TokenStatus::Active->value,
        ]);
    }

    public function test_walk_ins_bootstrap_only_returns_current_day_walk_ins(): void
    {
        [$user, $branch, $service] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);
        $yesterday = now()->copy()->subDay()->startOfDay()->addHours(10);
        $today = now()->copy()->startOfDay()->addHours(11);

        try {
            $this->travelTo($yesterday);
            $yesterdayToken = $this->loginAndReturnToken($user);

            $this->withToken($yesterdayToken)
                ->postJson('/api/dashboard/walk-ins', [
                    'branch_id' => $branch->getKey(),
                    'service_id' => $service->getKey(),
                    'customer_name' => 'Yesterday Walk In',
                    'phone_number' => '+213500000111',
                    'email_address' => 'yesterday.walkin@example.test',
                    'ticket_source' => 'reception',
                ])
                ->assertCreated();

            $this->travelTo($today);
            $todayToken = $this->loginAndReturnToken($user);

            $this->withToken($todayToken)
                ->postJson('/api/dashboard/walk-ins', [
                    'branch_id' => $branch->getKey(),
                    'service_id' => $service->getKey(),
                    'customer_name' => 'Today Walk In',
                    'phone_number' => '+213500000222',
                    'email_address' => 'today.walkin@example.test',
                    'ticket_source' => 'reception',
                ])
                ->assertCreated();

            $this->withToken($todayToken)
                ->getJson('/api/dashboard/walk-ins/bootstrap')
                ->assertOk()
                ->assertJsonCount(1, 'data.tickets')
                ->assertJsonCount(1, 'data.customers')
                ->assertJsonCount(1, 'data.sessions')
                ->assertJsonPath('data.customers.0.fullName', 'Today Walk In');
        } finally {
            $this->travelBack();
        }
    }

    public function test_analytics_bootstrap_returns_metrics_payload(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $appointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Analytics Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '09:00:00',
        );
        $this->createQueueEntryForAppointment($appointment, 1, QueueEntryStatus::Waiting);

        $this->withToken($token)
            ->getJson('/api/dashboard/analytics/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.branches.0', 'All Branches')
            ->assertJsonPath('data.trafficByRange.day.0.appointments', 1)
            ->assertJsonCount(11, 'data.baseHeatmap')
            ->assertJsonPath('data.serviceFunnel.0.stage', 'Booked');
    }

    public function test_cancelling_appointment_expires_active_qr_token_and_closes_queue_entry(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $appointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Cancelled QR Appointment',
            status: AppointmentStatus::Confirmed,
            appointmentDate: now()->toDateString(),
            appointmentTime: '12:00:00',
        );
        $qrToken = $this->createAppointmentQrToken($appointment);
        $queueEntry = $this->createQueueEntryForAppointment($appointment, 1, QueueEntryStatus::Waiting);

        $this->withToken($token)
            ->patchJson('/api/dashboard/appointments/'.$appointment->getKey().'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'Cancelled');

        $this->assertDatabaseHas('qr_code_tokens', [
            'id' => $qrToken->getKey(),
            'token_status' => TokenStatus::Expired->value,
        ]);
        $this->assertDatabaseHas('queue_entries', [
            'id' => $queueEntry->getKey(),
            'queue_status' => QueueEntryStatus::Cancelled->value,
        ]);
    }

    public function test_deleting_appointment_with_check_in_history_soft_deletes_without_fk_error(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $appointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Delete Flow Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '11:30:00',
        );
        $qrToken = $this->createAppointmentQrToken($appointment, TokenStatus::Active);

        $kiosk = KioskDevice::query()->create([
            'branch_id' => $branch->getKey(),
            'device_identifier' => 'KSK-'.Str::upper(Str::random(8)),
            'device_location_description' => 'Main Entrance',
            'device_status' => 'online',
        ]);

        $checkInRecord = CheckInRecord::query()->create([
            'qr_token_id' => $qrToken->getKey(),
            'kiosk_id' => $kiosk->getKey(),
            'customer_id' => $appointment->customer_id,
            'check_in_date_time' => now(),
            'check_in_result' => CheckInResult::Success,
        ]);

        $this->withToken($token)
            ->deleteJson('/api/dashboard/appointments/'.$appointment->getKey())
            ->assertOk()
            ->assertJsonPath('data.id', $appointment->getKey())
            ->assertJsonPath('data.deleted', true);

        $this->assertSoftDeleted('appointments', [
            'id' => $appointment->getKey(),
        ]);
        $this->assertDatabaseHas('check_in_records', [
            'id' => $checkInRecord->getKey(),
            'qr_token_id' => $qrToken->getKey(),
        ]);
    }

    public function test_queue_monitor_complete_resequences_entries_without_queue_position_collision(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $servingAppointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Serving Queue Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '09:00:00',
        );
        $nextAppointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Next Queue Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '09:10:00',
        );

        $servingEntry = $this->createQueueEntryForAppointment($servingAppointment, 1, QueueEntryStatus::Serving);
        $nextEntry = $this->createQueueEntryForAppointment($nextAppointment, 2, QueueEntryStatus::Next);

        $this->withToken($token)
            ->patchJson('/api/dashboard/queue-monitor/entries/'.$servingEntry->getKey().'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'Completed');

        $this->assertDatabaseHas('queue_entries', [
            'id' => $servingEntry->getKey(),
            'queue_status' => QueueEntryStatus::Completed->value,
            'queue_position' => 3,
        ]);
        $this->assertDatabaseHas('queue_entries', [
            'id' => $nextEntry->getKey(),
            'queue_status' => QueueEntryStatus::Serving->value,
            'queue_position' => 1,
        ]);
    }

    public function test_queue_monitor_complete_requires_serving_entry(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $appointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Next Queue Entry',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '10:15:00',
        );

        $nextEntry = $this->createQueueEntryForAppointment($appointment, 1, QueueEntryStatus::Next);

        $this->withToken($token)
            ->patchJson('/api/dashboard/queue-monitor/entries/'.$nextEntry->getKey().'/complete')
            ->assertUnprocessable()
            ->assertJsonPath('errors.entry.0', 'Only the currently serving ticket can be completed.');
    }

    public function test_live_queue_returns_active_appointment_snapshot(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $appointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Live Queue Customer',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '10:30:00',
        );

        $entry = $this->createQueueEntryForAppointment($appointment, 1, QueueEntryStatus::Serving);
        $expectedTicketId = BookingCodeFormatter::queueEntryDisplayCode(
            $entry->fresh([
                'queueSession.branch.company',
                'queueSession.service',
                'appointment.customer.user',
                'appointment.branch.company',
                'appointment.service',
                'walkInTicket.customer.user',
                'customer.user',
            ])
        );

        $this->withToken($token)
            ->getJson('/api/dashboard/live-queue')
            ->assertOk()
            ->assertJsonPath('data.hasActiveEntry', true)
            ->assertJsonPath('data.queueStatus', 'serving')
            ->assertJsonPath('data.ticketId', $expectedTicketId)
            ->assertJsonPath('data.ticketPrefix', 'A')
            ->assertJsonPath('data.customerName', 'Live Queue Customer')
            ->assertJsonPath('data.serviceName', $service->service_name)
            ->assertJsonPath('data.branchName', $branch->branch_name)
            ->assertJsonPath('data.queuePosition', 1)
            ->assertJsonPath('data.estimatedWaitMinutes', 0);
    }

    public function test_live_queue_hides_special_needs_entry_until_check_in(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $specialAppointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Live Queue Special Hidden',
            status: AppointmentStatus::Active,
            appointmentDate: now()->toDateString(),
            appointmentTime: '10:00:00',
            customerUserType: 'special_needs',
        );

        $session = DailyQueueSession::query()->firstOrCreate(
            [
                'branch_id' => $branch->getKey(),
                'service_id' => $service->getKey(),
                'session_date' => now()->toDateString(),
            ],
            [
                'session_start_time' => '08:00:00',
                'session_end_time' => '17:00:00',
                'session_status' => QueueSessionStatus::Live,
            ]
        );

        QueueEntry::query()->create([
            'queue_session_id' => $session->getKey(),
            'customer_id' => $specialAppointment->customer_id,
            'queue_position' => 1,
            'queue_status' => QueueEntryStatus::Next,
            'checked_in_at' => null,
            'appointment_id' => $specialAppointment->getKey(),
        ]);

        $this->withToken($token)
            ->getJson('/api/dashboard/live-queue')
            ->assertOk()
            ->assertJsonPath('data.hasActiveEntry', false);
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

    public function test_dashboard_endpoints_are_scoped_to_authenticated_company(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext([
            'company_name' => 'Scoped Company',
            'branch_name' => 'Scoped Branch',
            'service_name' => 'Scoped Service',
            'user_email' => 'scoped@example.test',
            'staff_name' => 'Scoped Admin',
        ]);
        [$foreignUser, $foreignBranch, $foreignService, $foreignStaff] = $this->createDashboardContext([
            'company_name' => 'Foreign Company',
            'branch_name' => 'Foreign Branch',
            'service_name' => 'Foreign Service',
            'user_email' => 'foreign@example.test',
            'staff_name' => 'Foreign Admin',
        ]);

        $this->createAppointmentRecord($branch, $service, $staff, 'Scoped Customer');
        $this->createAppointmentRecord($foreignBranch, $foreignService, $foreignStaff, 'Foreign Customer');

        $token = $this->loginAndReturnToken($user);

        $this->withToken($token)
            ->getJson('/api/dashboard/branches')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Scoped Branch'])
            ->assertJsonMissing(['name' => 'Foreign Branch']);

        $this->withToken($token)
            ->getJson('/api/dashboard/services')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Scoped Service'])
            ->assertJsonMissing(['name' => 'Foreign Service']);

        $this->withToken($token)
            ->getJson('/api/dashboard/staff')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Scoped Admin'])
            ->assertJsonMissing(['name' => 'Foreign Admin']);

        $this->withToken($token)
            ->getJson('/api/dashboard/appointments/bootstrap')
            ->assertOk()
            ->assertJsonFragment(['label' => 'Scoped Branch'])
            ->assertJsonFragment(['label' => 'Scoped Service'])
            ->assertJsonMissing(['label' => 'Foreign Branch'])
            ->assertJsonMissing(['label' => 'Foreign Service']);

        $this->withToken($token)
            ->getJson('/api/dashboard/layout/top-navbar')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Scoped Customer'])
            ->assertJsonMissing(['name' => 'Foreign Customer']);

        $this->withToken($token)
            ->getJson('/api/dashboard/branches/'.$foreignBranch->getKey())
            ->assertNotFound();
    }

    public function test_branches_index_supports_filters_and_pagination(): void
    {
        [$user, $branch] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        Branch::query()->create([
            'company_id' => $branch->company_id,
            'branch_name' => 'North Hub',
            'branch_address' => 'North District',
            'branch_code' => 'NTH-100',
            'branch_status' => 'maintenance',
        ]);

        $this->withToken($token)
            ->getJson('/api/dashboard/branches?search=North&status=maintenance&paginate=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('meta.pagination.per_page', 1)
            ->assertJsonPath('data.0.name', 'North Hub');
    }

    public function test_branch_can_be_created_from_dashboard(): void
    {
        [$user, $branch] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $this->withToken($token)
            ->postJson('/api/dashboard/branches', [
                'name' => 'Harbor Branch',
                'code' => 'HBR-301',
                'address' => 'Harbor District',
                'status' => 'active',
                'latitude' => 36.7525,
                'longitude' => 3.0420,
                'pin_top' => 42,
                'pin_left' => 58,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Harbor Branch')
            ->assertJsonPath('data.code', 'HBR-301')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('branches', [
            'company_id' => $branch->company_id,
            'branch_name' => 'Harbor Branch',
            'branch_code' => 'HBR-301',
            'branch_address' => 'Harbor District',
            'branch_status' => 'active',
        ]);
    }

    public function test_branch_can_be_updated_from_dashboard(): void
    {
        [$user, $branch] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $this->withToken($token)
            ->patchJson('/api/dashboard/branches/'.$branch->getKey(), [
                'name' => 'Downtown Executive Hub',
                'code' => 'HQ-900',
                'address' => 'Executive District',
                'status' => 'peak',
                'latitude' => 36.7511,
                'longitude' => 3.0512,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Downtown Executive Hub')
            ->assertJsonPath('data.code', 'HQ-900')
            ->assertJsonPath('data.status', 'peak');

        $this->assertDatabaseHas('branches', [
            'id' => $branch->getKey(),
            'branch_name' => 'Downtown Executive Hub',
            'branch_code' => 'HQ-900',
            'branch_address' => 'Executive District',
            'branch_status' => 'peak',
        ]);
    }

    public function test_branch_without_linked_records_can_be_deleted(): void
    {
        [$user, $branch] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $deletableBranch = Branch::query()->create([
            'company_id' => $branch->company_id,
            'branch_name' => 'Archive Candidate',
            'branch_address' => 'Quiet District',
            'branch_code' => 'ARC-404',
            'branch_status' => 'maintenance',
        ]);

        $this->withToken($token)
            ->deleteJson('/api/dashboard/branches/'.$deletableBranch->getKey())
            ->assertOk()
            ->assertJsonPath('data.id', $deletableBranch->getKey())
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('branches', [
            'id' => $deletableBranch->getKey(),
        ]);
    }

    public function test_branch_with_linked_records_cannot_be_deleted(): void
    {
        [$user, $branch] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $this->withToken($token)
            ->deleteJson('/api/dashboard/branches/'.$branch->getKey())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch']);

        $this->assertDatabaseHas('branches', [
            'id' => $branch->getKey(),
        ]);
    }

    public function test_services_index_supports_filters_and_pagination(): void
    {
        [$user, $branch] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $secondaryBranch = Branch::query()->create([
            'company_id' => $branch->company_id,
            'branch_name' => 'South Hub',
            'branch_address' => 'South District',
            'branch_code' => 'STH-200',
            'branch_status' => 'active',
        ]);

        $service = Service::query()->create([
            'branch_id' => $secondaryBranch->getKey(),
            'service_name' => 'Premium Advisory',
            'average_service_duration_minutes' => 25,
            'is_active' => false,
            'service_code' => 'SRV-777',
            'service_subtitle' => 'Premium lane',
            'service_description' => 'High value advisory flow.',
            'service_icon' => 'corporate',
        ]);
        $service->branches()->sync([$secondaryBranch->getKey()]);

        $this->withToken($token)
            ->getJson('/api/dashboard/services?search=Premium&status=Inactive&branch_id='.$secondaryBranch->getKey().'&paginate=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.name', 'Premium Advisory');
    }

    public function test_staff_index_supports_filters_and_pagination(): void
    {
        [$user, $branch] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $secondaryBranch = Branch::query()->create([
            'company_id' => $branch->company_id,
            'branch_name' => 'Support Hub',
            'branch_address' => 'Support District',
            'branch_code' => 'SUP-300',
            'branch_status' => 'active',
        ]);

        $this->createStaffMemberRecord(
            branch: $secondaryBranch,
            name: 'Nora Support',
            email: 'nora.support@example.test',
            role: UserRoleName::Staff,
            status: EmploymentStatus::OnLeave,
        );

        $this->withToken($token)
            ->getJson('/api/dashboard/staff?search=Nora&branch_id='.$secondaryBranch->getKey().'&role=Staff&status=On%20Leave&paginate=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.name', 'Nora Support');
    }

    public function test_staff_can_be_created_with_a_custom_password(): void
    {
        [$user, $branch, $service] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $response = $this->withToken($token)
            ->postJson('/api/dashboard/staff', [
                'name' => 'Secure Agent',
                'email' => 'secure.agent@example.test',
                'password' => 'StaffPass123!',
                'password_confirmation' => 'StaffPass123!',
                'branch_id' => $branch->getKey(),
                'service_id' => $service->getKey(),
                'role' => 'Staff',
                'status' => 'Active',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Secure Agent')
            ->assertJsonPath('data.email', 'secure.agent@example.test')
            ->assertJsonPath('data.serviceId', $service->getKey())
            ->assertJsonPath('data.serviceName', $service->service_name);

        $createdUser = User::query()
            ->where('email', 'secure.agent@example.test')
            ->firstOrFail();

        $this->assertTrue(Hash::check('StaffPass123!', $createdUser->password_hash));
        $this->assertTrue((bool) $createdUser->is_active);
        $this->assertSame(
            'Staff',
            $response->json('data.role')
        );

        $this->assertDatabaseHas('staff_members', [
            'user_id' => $createdUser->getKey(),
            'branch_id' => $branch->getKey(),
            'service_id' => $service->getKey(),
        ]);
    }

    public function test_staff_role_requires_a_service_assignment(): void
    {
        [$user, $branch] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $this->withToken($token)
            ->postJson('/api/dashboard/staff', [
                'name' => 'Service Missing',
                'email' => 'service.missing@example.test',
                'password' => 'StaffPass123!',
                'password_confirmation' => 'StaffPass123!',
                'branch_id' => $branch->getKey(),
                'role' => 'Staff',
                'status' => 'Active',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_id']);
    }

    public function test_admin_can_be_created_without_a_service_assignment(): void
    {
        [$user, $branch] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $response = $this->withToken($token)
            ->postJson('/api/dashboard/staff', [
                'name' => 'Branch Admin',
                'email' => 'branch.admin@example.test',
                'password' => 'AdminPass123!',
                'password_confirmation' => 'AdminPass123!',
                'branch_id' => $branch->getKey(),
                'role' => 'Admin',
                'status' => 'Active',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.role', 'Admin')
            ->assertJsonPath('data.serviceId', null);

        $createdUser = User::query()
            ->where('email', 'branch.admin@example.test')
            ->firstOrFail();

        $this->assertDatabaseHas('staff_members', [
            'user_id' => $createdUser->getKey(),
            'branch_id' => $branch->getKey(),
            'service_id' => null,
        ]);
    }

    public function test_switching_staff_to_admin_clears_service_assignment(): void
    {
        [$user, $branch, $service] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $response = $this->withToken($token)
            ->postJson('/api/dashboard/staff', [
                'name' => 'Service Operator',
                'email' => 'service.operator@example.test',
                'password' => 'StaffPass123!',
                'password_confirmation' => 'StaffPass123!',
                'branch_id' => $branch->getKey(),
                'service_id' => $service->getKey(),
                'role' => 'Staff',
                'status' => 'Active',
            ])
            ->assertStatus(201);

        $staffId = $response->json('data.id');

        $this->withToken($token)
            ->patchJson('/api/dashboard/staff/'.$staffId, [
                'role' => 'Admin',
                'status' => 'Active',
            ])
            ->assertOk()
            ->assertJsonPath('data.role', 'Admin')
            ->assertJsonPath('data.serviceId', null)
            ->assertJsonPath('data.serviceName', null);

        $this->assertDatabaseHas('staff_members', [
            'id' => $staffId,
            'service_id' => null,
        ]);
    }

    public function test_appointments_index_supports_filters_and_pagination(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $queuedAppointment = $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Lina Search',
            status: AppointmentStatus::Pending,
            appointmentDate: now()->addDay()->toDateString(),
            appointmentTime: '10:00:00',
        );
        $this->createQueueEntryForAppointment($queuedAppointment, 2, QueueEntryStatus::Waiting);

        $this->withToken($token)
            ->getJson('/api/dashboard/appointments?search=Lina&branch_id='.$branch->getKey().'&service_id='.$service->getKey().'&status=Pending&queue_state=Checked%20In&paginate=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.summary.totalAppointments', 1)
            ->assertJsonPath('data.appointments.0.customerName', 'Lina Search');
    }

    public function test_appointments_index_accepts_literal_boolean_paginate_query_value(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);

        $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Boolean Pagination',
            status: AppointmentStatus::Confirmed,
            appointmentDate: now()->addDay()->toDateString(),
            appointmentTime: '11:30:00',
        );

        $this->withToken($token)
            ->getJson('/api/dashboard/appointments?paginate=true&per_page=20')
            ->assertOk()
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.summary.totalAppointments', 1)
            ->assertJsonPath('data.appointments.0.customerName', 'Boolean Pagination');
    }

    public function test_appointments_index_uses_service_duration_for_time_slot(): void
    {
        [$user, $branch, $service, $staff] = $this->createDashboardContext([
            'service_duration' => 45,
        ]);
        $token = $this->loginAndReturnToken($user);

        $this->createAppointmentRecord(
            branch: $branch,
            service: $service,
            staff: $staff,
            customerName: 'Duration Based Slot',
            status: AppointmentStatus::Confirmed,
            appointmentDate: now()->addDay()->toDateString(),
            appointmentTime: '09:15:00',
        );

        $this->withToken($token)
            ->getJson('/api/dashboard/appointments?paginate=1&per_page=20')
            ->assertOk()
            ->assertJsonPath('data.appointments.0.timeSlot', '09:15 AM - 10:00 AM');
    }

    public function test_appointments_queue_state_filters_treat_past_same_day_slots_as_expired(): void
    {
        $reference = now()->startOfDay()->addHours(14);
        $this->travelTo($reference);

        try {
            [$user, $branch, $service, $staff] = $this->createDashboardContext();
            $token = $this->loginAndReturnToken($user);

            $this->createAppointmentRecord(
                branch: $branch,
                service: $service,
                staff: $staff,
                customerName: 'Expired Same Day',
                status: AppointmentStatus::Confirmed,
                appointmentDate: $reference->toDateString(),
                appointmentTime: '13:00:00',
            );

            $this->createAppointmentRecord(
                branch: $branch,
                service: $service,
                staff: $staff,
                customerName: 'Upcoming Same Day',
                status: AppointmentStatus::Confirmed,
                appointmentDate: $reference->toDateString(),
                appointmentTime: '16:00:00',
            );

            $this->withToken($token)
                ->getJson('/api/dashboard/appointments?queue_state=Expired&paginate=1&per_page=20')
                ->assertOk()
                ->assertJsonPath('data.pagination.total', 1)
                ->assertJsonPath('data.appointments.0.customerName', 'Expired Same Day')
                ->assertJsonPath('data.appointments.0.queueState', 'Expired');

            $this->withToken($token)
                ->getJson('/api/dashboard/appointments?queue_state=Awaiting%20Check-In&paginate=1&per_page=20')
                ->assertOk()
                ->assertJsonPath('data.pagination.total', 1)
                ->assertJsonPath('data.appointments.0.customerName', 'Upcoming Same Day')
                ->assertJsonPath('data.appointments.0.queueState', 'Awaiting Check-In');
        } finally {
            $this->travelBack();
        }
    }

    public function test_dashboard_cache_version_is_incremented_after_company_mutation(): void
    {
        [$user, $branch] = $this->createDashboardContext();
        $token = $this->loginAndReturnToken($user);
        $versionKey = 'dashboard:cache-version:'.$branch->company_id;

        Cache::forget($versionKey);

        $this->withToken($token)
            ->patchJson('/api/dashboard/branches/'.$branch->getKey().'/status', [
                'status' => 'maintenance',
            ])
            ->assertOk();

        $this->assertSame(2, Cache::get($versionKey));
    }

    protected function loginAndReturnToken(User $user): string
    {
        $response = $this->postJson('/api/dashboard/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response
            ->assertOk()
            ->assertCookie((string) config('jwt.cookie_name'));

        return $this->extractAuthTokenFromResponse($response);
    }

    protected function extractAuthTokenFromResponse($response): string
    {
        $cookieName = (string) config('jwt.cookie_name');
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === $cookieName);

        $this->assertNotNull($cookie, sprintf('Expected auth cookie [%s] to be present on the response.', $cookieName));

        return $cookie->getValue();
    }

    protected function loginMobileAndReturnToken(User $user): string
    {
        $response = $this->postJson('/api/mobile/auth/login', [
            'identifier' => $user->phone_number,
            'password' => 'password123',
        ]);

        $response->assertOk();

        return (string) $response->json('data.token');
    }

    protected function createDashboardContext(array $overrides = []): array
    {
        $suffix = Str::lower(Str::random(6));
        $company = Company::query()->create([
            'company_name' => $overrides['company_name'] ?? 'SmartQdz',
            'company_status' => CompanyStatus::Active,
        ]);

        $branch = Branch::query()->create([
            'company_id' => $company->getKey(),
            'branch_name' => $overrides['branch_name'] ?? 'Downtown HQ',
            'branch_address' => $overrides['branch_address'] ?? 'Business District',
            'branch_code' => $overrides['branch_code'] ?? 'HQ-'.strtoupper(Str::substr($suffix, 0, 3)),
            'branch_status' => $overrides['branch_status'] ?? 'active',
        ]);

        $service = Service::query()->create([
            'branch_id' => $branch->getKey(),
            'service_name' => $overrides['service_name'] ?? 'General Inquiry',
            'average_service_duration_minutes' => $overrides['service_duration'] ?? 10,
            'is_active' => $overrides['service_active'] ?? true,
            'service_code' => $overrides['service_code'] ?? 'SRV-'.random_int(100, 999),
            'service_subtitle' => $overrides['service_subtitle'] ?? 'General desk',
            'service_description' => $overrides['service_description'] ?? 'Default support queue for standard operations.',
            'service_icon' => $overrides['service_icon'] ?? 'support',
        ]);
        $service->branches()->sync([$branch->getKey()]);

        $user = User::query()->create([
            'email' => $overrides['user_email'] ?? 'admin.'.$suffix.'@example.test',
            'phone_number' => $overrides['user_phone'] ?? '+2135'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'password_hash' => 'password123',
            'is_active' => true,
        ]);

        UserRole::query()->create([
            'user_id' => $user->getKey(),
            'role_name' => UserRoleName::Admin,
        ]);

        $staff = StaffMember::query()->create([
            'user_id' => $user->getKey(),
            'company_id' => $company->getKey(),
            'branch_id' => $branch->getKey(),
            'full_name' => $overrides['staff_name'] ?? 'Dashboard Admin',
            'display_staff_code' => $overrides['staff_code'] ?? 'STF-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'employment_status' => $overrides['staff_status'] ?? EmploymentStatus::Active,
            'is_online' => $overrides['staff_online'] ?? true,
            'last_active_at' => now(),
        ]);

        return [$user, $branch, $service, $staff];
    }

    protected function createStaffMemberRecord(
        Branch $branch,
        string $name,
        string $email,
        UserRoleName $role,
        EmploymentStatus $status,
    ): StaffMember {
        $user = User::query()->create([
            'email' => $email,
            'phone_number' => '+2135'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'password_hash' => 'password123',
            'is_active' => $status !== EmploymentStatus::Inactive,
        ]);

        UserRole::query()->create([
            'user_id' => $user->getKey(),
            'role_name' => $role,
        ]);

        return StaffMember::query()->create([
            'user_id' => $user->getKey(),
            'company_id' => $branch->company_id,
            'branch_id' => $branch->getKey(),
            'full_name' => $name,
            'display_staff_code' => 'STF-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'employment_status' => $status,
            'is_online' => $status === EmploymentStatus::Active,
            'last_active_at' => now(),
        ]);
    }

    protected function createAppointmentRecord(
        Branch $branch,
        Service $service,
        StaffMember $staff,
        string $customerName,
        AppointmentStatus $status = AppointmentStatus::Confirmed,
        ?string $appointmentDate = null,
        string $appointmentTime = '09:00:00',
        ?string $customerUserType = null,
    ): Appointment {
        $customerUserId = null;

        if ($customerUserType !== null) {
            $customerUser = User::query()->create([
                'email' => Str::slug($customerName).'.'.Str::lower(Str::random(4)).'.user@example.test',
                'phone_number' => '+2135'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
                'password_hash' => 'password123',
                'user_type' => $customerUserType,
                'is_active' => true,
            ]);

            UserRole::query()->create([
                'user_id' => $customerUser->getKey(),
                'role_name' => UserRoleName::Customer,
            ]);

            $customerUserId = $customerUser->getKey();
        }

        $customer = Customer::query()->create([
            'user_id' => $customerUserId,
            'full_name' => $customerName,
            'phone_number' => '+2135'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'email_address' => Str::slug($customerName).'.'.Str::lower(Str::random(4)).'@example.test',
        ]);

        return Appointment::query()->create([
            'customer_id' => $customer->getKey(),
            'branch_id' => $branch->getKey(),
            'service_id' => $service->getKey(),
            'staff_id' => $staff->getKey(),
            'appointment_date' => $appointmentDate ?? now()->addDay()->toDateString(),
            'appointment_time' => $appointmentTime,
            'appointment_status' => $status,
        ]);
    }

    protected function createQueueEntryForAppointment(
        Appointment $appointment,
        int $position,
        QueueEntryStatus $status,
    ): QueueEntry {
        $session = DailyQueueSession::query()->firstOrCreate(
            [
                'branch_id' => $appointment->branch_id,
                'service_id' => $appointment->service_id,
                'session_date' => $appointment->appointment_date,
            ],
            [
                'session_start_time' => '08:00:00',
                'session_end_time' => '17:00:00',
                'session_status' => QueueSessionStatus::Live,
            ]
        );

        return QueueEntry::query()->create([
            'queue_session_id' => $session->getKey(),
            'customer_id' => $appointment->customer_id,
            'queue_position' => $position,
            'queue_status' => $status,
            'checked_in_at' => now(),
            'appointment_id' => $appointment->getKey(),
        ]);
    }

    protected function createAppointmentQrToken(
        Appointment $appointment,
        TokenStatus $status = TokenStatus::Active,
    ): QrCodeToken {
        return QrCodeToken::query()->create([
            'token_value' => Str::upper(Str::random(24)),
            'expiration_date_time' => now()->addHours(8),
            'used_date_time' => $status === TokenStatus::Consumed ? now() : null,
            'token_status' => $status,
            'appointment_id' => $appointment->getKey(),
        ]);
    }

    protected function createMobileCustomerContext(array $overrides = []): array
    {
        $suffix = Str::lower(Str::random(6));
        $user = User::query()->create([
            'email' => $overrides['user_email'] ?? 'mobile.'.$suffix.'@example.test',
            'phone_number' => $overrides['user_phone'] ?? '+2135'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'password_hash' => 'password123',
            'user_type' => $overrides['user_type'] ?? 'regular',
            'is_active' => true,
        ]);

        UserRole::query()->create([
            'user_id' => $user->getKey(),
            'role_name' => UserRoleName::Customer,
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->getKey(),
            'full_name' => $overrides['full_name'] ?? 'Mobile Customer',
            'phone_number' => $user->phone_number,
            'email_address' => $user->email,
        ]);

        return [$user, $customer];
    }
}
