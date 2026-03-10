<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\CheckInResult;
use App\Enums\CompanyStatus;
use App\Enums\DeviceStatus;
use App\Enums\EmploymentStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\QueueEntryStatus;
use App\Enums\QueueSessionStatus;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TokenStatus;
use App\Enums\UserRoleName;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CheckInRecord;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DailyQueueSession;
use App\Models\KioskDevice;
use App\Models\Notification;
use App\Models\QrCodeToken;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\UserRole;
use App\Models\WalkInTicket;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DashboardDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $company = $this->seedCompany();
        $branches = $this->seedBranches($company);
        $services = $this->seedServices($branches);
        $staff = $this->seedStaff($company, $branches);
        $customers = $this->seedCustomers();

        $this->seedPreferences($staff['admin@smartqdz.local']['user']);
        $kiosks = $this->seedKiosks($branches);
        $appointments = $this->seedAppointments($branches, $services, $staff, $customers);

        $this->seedWalkInsAndQueue($branches, $services, $staff, $customers, $appointments, $kiosks);
        $this->seedNotifications($staff['admin@smartqdz.local']['user']);
    }

    protected function seedCompany(): Company
    {
        return Company::query()->updateOrCreate(
            ['company_name' => 'SmartQdz'],
            ['company_status' => CompanyStatus::Active]
        );
    }

    protected function seedBranches(Company $company): array
    {
        $definitions = [
            [
                'branch_code' => 'HQ-001',
                'branch_name' => 'Downtown HQ',
                'branch_address' => 'Main Business District, Algiers',
                'branch_status' => 'active',
                'latitude' => 36.7538,
                'longitude' => 3.0588,
                'pin_top' => 32,
                'pin_left' => 46,
            ],
            [
                'branch_code' => 'ALG-002',
                'branch_name' => 'Bab Ezzouar Hub',
                'branch_address' => 'Commercial Center, Bab Ezzouar',
                'branch_status' => 'peak',
                'latitude' => 36.7262,
                'longitude' => 3.1822,
                'pin_top' => 48,
                'pin_left' => 63,
            ],
            [
                'branch_code' => 'ORN-003',
                'branch_name' => 'Oran Service Point',
                'branch_address' => 'Front de Mer, Oran',
                'branch_status' => 'maintenance',
                'latitude' => 35.6981,
                'longitude' => -0.6348,
                'pin_top' => 67,
                'pin_left' => 24,
            ],
        ];

        $branches = [];

        foreach ($definitions as $definition) {
            $branch = Branch::query()->updateOrCreate(
                ['branch_code' => $definition['branch_code']],
                [
                    'company_id' => $company->getKey(),
                    'branch_name' => $definition['branch_name'],
                    'branch_address' => $definition['branch_address'],
                    'branch_status' => $definition['branch_status'],
                    'latitude' => $definition['latitude'],
                    'longitude' => $definition['longitude'],
                    'pin_top' => $definition['pin_top'],
                    'pin_left' => $definition['pin_left'],
                ]
            );

            $branches[$definition['branch_code']] = $branch;
        }

        return $branches;
    }

    protected function seedServices(array $branches): array
    {
        $definitions = [
            [
                'service_code' => 'SRV-001',
                'service_name' => 'General Inquiry',
                'service_subtitle' => 'Everyday support desk',
                'service_description' => 'General support queue for standard customer requests.',
                'service_icon' => 'support',
                'average_service_duration_minutes' => 10,
                'is_active' => true,
                'primary_branch_code' => 'HQ-001',
                'branch_codes' => ['HQ-001', 'ALG-002', 'ORN-003'],
            ],
            [
                'service_code' => 'SRV-002',
                'service_name' => 'Cash Deposit',
                'service_subtitle' => 'Fast cash operations',
                'service_description' => 'Cash deposit and payment support with fast turnaround.',
                'service_icon' => 'cash',
                'average_service_duration_minutes' => 8,
                'is_active' => true,
                'primary_branch_code' => 'HQ-001',
                'branch_codes' => ['HQ-001', 'ALG-002'],
            ],
            [
                'service_code' => 'SRV-003',
                'service_name' => 'Premium Consultation',
                'service_subtitle' => 'High-value advisory',
                'service_description' => 'Dedicated consultation flow for premium and enterprise customers.',
                'service_icon' => 'corporate',
                'average_service_duration_minutes' => 20,
                'is_active' => true,
                'primary_branch_code' => 'HQ-001',
                'branch_codes' => ['HQ-001'],
            ],
            [
                'service_code' => 'SRV-004',
                'service_name' => 'Card Support',
                'service_subtitle' => 'Cards and replacements',
                'service_description' => 'Support for card activation, renewal, and replacement.',
                'service_icon' => 'card',
                'average_service_duration_minutes' => 12,
                'is_active' => true,
                'primary_branch_code' => 'ORN-003',
                'branch_codes' => ['HQ-001', 'ORN-003'],
            ],
            [
                'service_code' => 'SRV-005',
                'service_name' => 'Business Services',
                'service_subtitle' => 'SME operations desk',
                'service_description' => 'Dedicated operational queue for small business customers.',
                'service_icon' => 'wallet',
                'average_service_duration_minutes' => 15,
                'is_active' => true,
                'primary_branch_code' => 'ALG-002',
                'branch_codes' => ['ALG-002'],
            ],
        ];

        $services = [];

        foreach ($definitions as $definition) {
            $service = Service::query()->updateOrCreate(
                ['service_code' => $definition['service_code']],
                [
                    'branch_id' => $branches[$definition['primary_branch_code']]->getKey(),
                    'service_name' => $definition['service_name'],
                    'service_subtitle' => $definition['service_subtitle'],
                    'service_description' => $definition['service_description'],
                    'service_icon' => $definition['service_icon'],
                    'average_service_duration_minutes' => $definition['average_service_duration_minutes'],
                    'is_active' => $definition['is_active'],
                ]
            );

            $service->branches()->sync(
                collect($definition['branch_codes'])
                    ->map(fn (string $branchCode) => $branches[$branchCode]->getKey())
                    ->all()
            );

            $services[$definition['service_code']] = $service;
        }

        return $services;
    }

    protected function seedStaff(Company $company, array $branches): array
    {
        $definitions = [
            [
                'email' => 'admin@smartqdz.local',
                'phone_number' => '+213500000001',
                'full_name' => 'SmartQdz Admin',
                'display_staff_code' => 'STF-00001',
                'branch_code' => 'HQ-001',
                'role' => UserRoleName::Admin,
                'employment_status' => EmploymentStatus::Active,
                'is_online' => true,
                'avatar_url' => 'https://images.example.test/staff/admin.png',
            ],
            [
                'email' => 'yasmine.manager@smartqdz.local',
                'phone_number' => '+213500000002',
                'full_name' => 'Yasmine Bensalem',
                'display_staff_code' => 'STF-00002',
                'branch_code' => 'HQ-001',
                'role' => UserRoleName::Manager,
                'employment_status' => EmploymentStatus::Active,
                'is_online' => true,
                'avatar_url' => 'https://images.example.test/staff/yasmine.png',
            ],
            [
                'email' => 'amine.cashier@smartqdz.local',
                'phone_number' => '+213500000003',
                'full_name' => 'Amine Cherif',
                'display_staff_code' => 'STF-00003',
                'branch_code' => 'HQ-001',
                'role' => UserRoleName::Staff,
                'employment_status' => EmploymentStatus::Active,
                'is_online' => true,
                'avatar_url' => 'https://images.example.test/staff/amine.png',
            ],
            [
                'email' => 'nadia.branch@smartqdz.local',
                'phone_number' => '+213500000004',
                'full_name' => 'Nadia Ferhat',
                'display_staff_code' => 'STF-00004',
                'branch_code' => 'ALG-002',
                'role' => UserRoleName::Manager,
                'employment_status' => EmploymentStatus::Active,
                'is_online' => true,
                'avatar_url' => 'https://images.example.test/staff/nadia.png',
            ],
            [
                'email' => 'leila.reception@smartqdz.local',
                'phone_number' => '+213500000005',
                'full_name' => 'Leila Mahfoud',
                'display_staff_code' => 'STF-00005',
                'branch_code' => 'ALG-002',
                'role' => UserRoleName::Staff,
                'employment_status' => EmploymentStatus::Active,
                'is_online' => false,
                'avatar_url' => 'https://images.example.test/staff/leila.png',
            ],
            [
                'email' => 'samir.support@smartqdz.local',
                'phone_number' => '+213500000006',
                'full_name' => 'Samir Touati',
                'display_staff_code' => 'STF-00006',
                'branch_code' => 'ORN-003',
                'role' => UserRoleName::Support,
                'employment_status' => EmploymentStatus::OnLeave,
                'is_online' => false,
                'avatar_url' => 'https://images.example.test/staff/samir.png',
            ],
        ];

        $staff = [];

        foreach ($definitions as $definition) {
            $branch = $branches[$definition['branch_code']];

            $user = User::query()->updateOrCreate(
                ['email' => $definition['email']],
                [
                    'phone_number' => $definition['phone_number'],
                    'password_hash' => 'password123',
                    'is_active' => $definition['employment_status'] !== EmploymentStatus::Inactive,
                ]
            );

            UserRole::query()->where('user_id', $user->getKey())->delete();
            UserRole::query()->create([
                'user_id' => $user->getKey(),
                'role_name' => $definition['role'],
            ]);

            $member = StaffMember::query()->updateOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'company_id' => $company->getKey(),
                    'branch_id' => $branch->getKey(),
                    'full_name' => $definition['full_name'],
                    'display_staff_code' => $definition['display_staff_code'],
                    'employment_status' => $definition['employment_status'],
                    'avatar_url' => $definition['avatar_url'],
                    'is_online' => $definition['is_online'],
                    'last_active_at' => $definition['is_online'] ? now()->subMinutes(5) : now()->subHours(6),
                ]
            );

            $staff[$definition['email']] = [
                'user' => $user,
                'member' => $member,
            ];
        }

        return $staff;
    }

    protected function seedCustomers(): array
    {
        $definitions = [
            ['email' => 'karim.ait@example.test', 'full_name' => 'Karim Ait Ouali', 'phone_number' => '+213555100001'],
            ['email' => 'sabah.k@example.test', 'full_name' => 'Sabah Khellaf', 'phone_number' => '+213555100002'],
            ['email' => 'omar.benali@example.test', 'full_name' => 'Omar Benali', 'phone_number' => '+213555100003'],
            ['email' => 'lina.meziane@example.test', 'full_name' => 'Lina Meziane', 'phone_number' => '+213555100004'],
            ['email' => 'yacine.h@example.test', 'full_name' => 'Yacine Henniche', 'phone_number' => '+213555100005'],
            ['email' => 'imane.b@example.test', 'full_name' => 'Imane Boudiaf', 'phone_number' => '+213555100006'],
            ['email' => 'sofiane.r@example.test', 'full_name' => 'Sofiane Rahmani', 'phone_number' => '+213555100007'],
            ['email' => 'nadia.k@example.test', 'full_name' => 'Nadia Kaci', 'phone_number' => '+213555100008'],
            ['email' => 'farah.s@example.test', 'full_name' => 'Farah Sahraoui', 'phone_number' => '+213555100009'],
            ['email' => 'adel.t@example.test', 'full_name' => 'Adel Toumi', 'phone_number' => '+213555100010'],
            ['email' => 'malek.d@example.test', 'full_name' => 'Malek Dahmani', 'phone_number' => '+213555100011'],
            ['email' => 'rania.z@example.test', 'full_name' => 'Rania Zerrouki', 'phone_number' => '+213555100012'],
        ];

        $customers = [];

        foreach ($definitions as $definition) {
            $customers[$definition['email']] = Customer::query()->updateOrCreate(
                ['email_address' => $definition['email']],
                [
                    'user_id' => null,
                    'full_name' => $definition['full_name'],
                    'phone_number' => $definition['phone_number'],
                ]
            );
        }

        return $customers;
    }

    protected function seedPreferences(User $user): void
    {
        UserPreference::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'dashboard_settings' => [
                    'appearance' => [
                        'theme' => 'dark',
                        'language' => 'fr',
                        'density' => 'compact',
                        'reducedMotion' => false,
                        'surfaceStyle' => 'solid',
                    ],
                    'workspace' => [
                        'sidebarCollapsed' => false,
                        'defaultBranchScope' => 'all',
                        'rememberFilters' => true,
                        'stickyDetailsPanel' => true,
                        'compactTables' => true,
                    ],
                    'notifications' => [
                        'queueAlerts' => true,
                        'serviceAlerts' => true,
                        'desktopNotifications' => true,
                        'soundEffects' => true,
                        'dailySummary' => true,
                    ],
                    'security' => [
                        'maskSensitiveData' => false,
                        'confirmDestructiveActions' => true,
                        'autoLockEnabled' => true,
                        'sessionTimeoutMinutes' => 45,
                    ],
                ],
            ]
        );
    }

    protected function seedKiosks(array $branches): array
    {
        $definitions = [
            [
                'key' => 'hq-front',
                'branch_code' => 'HQ-001',
                'device_identifier' => 'KSK-HQ-01',
                'device_location_description' => 'Main lobby entrance',
                'device_status' => DeviceStatus::Online,
            ],
            [
                'key' => 'alg-business',
                'branch_code' => 'ALG-002',
                'device_identifier' => 'KSK-ALG-01',
                'device_location_description' => 'Business services hall',
                'device_status' => DeviceStatus::Busy,
            ],
            [
                'key' => 'orn-side',
                'branch_code' => 'ORN-003',
                'device_identifier' => 'KSK-ORN-01',
                'device_location_description' => 'Secondary counter zone',
                'device_status' => DeviceStatus::Maintenance,
            ],
        ];

        $kiosks = [];

        foreach ($definitions as $definition) {
            $kiosks[$definition['key']] = KioskDevice::query()->updateOrCreate(
                ['device_identifier' => $definition['device_identifier']],
                [
                    'branch_id' => $branches[$definition['branch_code']]->getKey(),
                    'device_location_description' => $definition['device_location_description'],
                    'device_status' => $definition['device_status'],
                ]
            );
        }

        return $kiosks;
    }

    protected function seedAppointments(
        array $branches,
        array $services,
        array $staff,
        array $customers,
    ): array {
        $definitions = [
            [
                'key' => 'appt-today-active',
                'customer_email' => 'karim.ait@example.test',
                'branch_code' => 'HQ-001',
                'service_code' => 'SRV-001',
                'staff_email' => 'yasmine.manager@smartqdz.local',
                'date' => today(),
                'time' => '09:30:00',
                'status' => AppointmentStatus::Active,
                'created_at' => now()->subHours(2),
            ],
            [
                'key' => 'appt-tomorrow-general',
                'customer_email' => 'sabah.k@example.test',
                'branch_code' => 'HQ-001',
                'service_code' => 'SRV-002',
                'staff_email' => 'amine.cashier@smartqdz.local',
                'date' => today()->copy()->addDay(),
                'time' => '10:00:00',
                'status' => AppointmentStatus::Confirmed,
                'created_at' => now()->subHours(5),
            ],
            [
                'key' => 'appt-plus-two-business',
                'customer_email' => 'omar.benali@example.test',
                'branch_code' => 'ALG-002',
                'service_code' => 'SRV-005',
                'staff_email' => 'nadia.branch@smartqdz.local',
                'date' => today()->copy()->addDays(2),
                'time' => '11:15:00',
                'status' => AppointmentStatus::Confirmed,
                'created_at' => now()->subHours(4),
            ],
            [
                'key' => 'appt-plus-three-card',
                'customer_email' => 'rania.z@example.test',
                'branch_code' => 'ORN-003',
                'service_code' => 'SRV-004',
                'staff_email' => 'samir.support@smartqdz.local',
                'date' => today()->copy()->addDays(3),
                'time' => '14:00:00',
                'status' => AppointmentStatus::Confirmed,
                'created_at' => now()->subHours(3),
            ],
            [
                'key' => 'appt-yesterday-noshow',
                'customer_email' => 'lina.meziane@example.test',
                'branch_code' => 'HQ-001',
                'service_code' => 'SRV-003',
                'staff_email' => 'yasmine.manager@smartqdz.local',
                'date' => today()->copy()->subDay(),
                'time' => '09:00:00',
                'status' => AppointmentStatus::NoShow,
                'created_at' => today()->copy()->subDays(2)->setTime(16, 0),
            ],
            [
                'key' => 'appt-two-days-cancelled',
                'customer_email' => 'nadia.k@example.test',
                'branch_code' => 'ALG-002',
                'service_code' => 'SRV-005',
                'staff_email' => 'nadia.branch@smartqdz.local',
                'date' => today()->copy()->subDays(2),
                'time' => '15:30:00',
                'status' => AppointmentStatus::Cancelled,
                'created_at' => today()->copy()->subDays(3)->setTime(12, 30),
            ],
        ];

        for ($offset = 0; $offset <= 6; $offset++) {
            $customerEmails = array_keys($customers);
            $definitions[] = [
                'key' => 'appt-history-'.$offset.'-morning',
                'customer_email' => $customerEmails[($offset + 2) % count($customerEmails)],
                'branch_code' => $offset % 2 === 0 ? 'HQ-001' : 'ALG-002',
                'service_code' => $offset % 3 === 0 ? 'SRV-001' : ($offset % 3 === 1 ? 'SRV-002' : 'SRV-005'),
                'staff_email' => $offset % 2 === 0 ? 'amine.cashier@smartqdz.local' : 'nadia.branch@smartqdz.local',
                'date' => today()->copy()->subDays($offset),
                'time' => '11:00:00',
                'status' => AppointmentStatus::Confirmed,
                'created_at' => today()->copy()->subDays($offset + 1)->setTime(9, 15),
            ];

            $definitions[] = [
                'key' => 'appt-history-'.$offset.'-afternoon',
                'customer_email' => $customerEmails[($offset + 5) % count($customerEmails)],
                'branch_code' => $offset % 2 === 0 ? 'ORN-003' : 'HQ-001',
                'service_code' => $offset % 2 === 0 ? 'SRV-004' : 'SRV-001',
                'staff_email' => $offset % 2 === 0 ? 'samir.support@smartqdz.local' : 'yasmine.manager@smartqdz.local',
                'date' => today()->copy()->subDays($offset),
                'time' => '14:30:00',
                'status' => $offset === 1 ? AppointmentStatus::NoShow : AppointmentStatus::Confirmed,
                'created_at' => today()->copy()->subDays($offset + 2)->setTime(11, 45),
            ];
        }

        $appointments = [];

        foreach ($definitions as $definition) {
            $startDateTime = $definition['date']->copy()->setTimeFromTimeString($definition['time']);
            $appointment = Appointment::query()->updateOrCreate(
                [
                    'customer_id' => $customers[$definition['customer_email']]->getKey(),
                    'branch_id' => $branches[$definition['branch_code']]->getKey(),
                    'service_id' => $services[$definition['service_code']]->getKey(),
                    'appointment_date' => $definition['date']->toDateString(),
                    'appointment_time' => $definition['time'],
                ],
                [
                    'staff_id' => $staff[$definition['staff_email']]['member']->getKey(),
                    'start_date_time' => $startDateTime,
                    'end_date_time' => $startDateTime->copy()->addMinutes(30),
                    'appointment_status' => $definition['status'],
                ]
            );

            $this->setTimestamps(
                $appointment,
                $definition['created_at'],
                $definition['date']->isToday() ? now()->subMinutes(25) : $definition['created_at']->copy()->addHours(2)
            );

            $appointments[$definition['key']] = $appointment;
        }

        return $appointments;
    }

    protected function seedWalkInsAndQueue(
        array $branches,
        array $services,
        array $staff,
        array $customers,
        array $appointments,
        array $kiosks,
    ): void {
        $ticketDefinitions = [
            [
                'key' => 'walkin-hq-serving',
                'customer_email' => 'omar.benali@example.test',
                'branch_code' => 'HQ-001',
                'service_code' => 'SRV-001',
                'ticket_number' => 1001,
                'ticket_source' => TicketSource::Reception,
                'ticket_status' => TicketStatus::Serving,
                'queue_status' => QueueEntryStatus::Serving,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(8, 40),
                'checked_in_at' => today()->copy()->setTime(8, 45),
                'service_started_at' => today()->copy()->setTime(8, 55),
                'served_by' => 'amine.cashier@smartqdz.local',
                'token_value' => 'QR-HQ-1001-DEMO',
                'token_status' => TokenStatus::Consumed,
                'token_used_at' => today()->copy()->setTime(8, 45),
                'check_in_result' => CheckInResult::Success,
                'kiosk_key' => 'hq-front',
                'notes' => 'Priority customer currently being served.',
            ],
            [
                'key' => 'walkin-hq-next',
                'customer_email' => 'lina.meziane@example.test',
                'branch_code' => 'HQ-001',
                'service_code' => 'SRV-001',
                'ticket_number' => 1002,
                'ticket_source' => TicketSource::Kiosk,
                'ticket_status' => TicketStatus::CheckedIn,
                'queue_status' => QueueEntryStatus::Next,
                'queue_position' => 2,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(8, 50),
                'checked_in_at' => today()->copy()->setTime(9, 0),
                'service_started_at' => null,
                'served_by' => null,
                'token_value' => 'QR-HQ-1002-DEMO',
                'token_status' => TokenStatus::Consumed,
                'token_used_at' => today()->copy()->setTime(9, 0),
                'check_in_result' => CheckInResult::Success,
                'kiosk_key' => 'hq-front',
                'notes' => 'Checked in and waiting for call.',
            ],
            [
                'key' => 'walkin-hq-waiting',
                'customer_email' => 'yacine.h@example.test',
                'branch_code' => 'HQ-001',
                'service_code' => 'SRV-001',
                'ticket_number' => 1003,
                'ticket_source' => TicketSource::QrScan,
                'ticket_status' => TicketStatus::Queued,
                'queue_status' => QueueEntryStatus::Waiting,
                'queue_position' => 3,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(9, 5),
                'checked_in_at' => null,
                'service_started_at' => null,
                'served_by' => null,
                'token_value' => 'QR-HQ-1003-DEMO',
                'token_status' => TokenStatus::Active,
                'token_used_at' => null,
                'check_in_result' => null,
                'kiosk_key' => null,
                'notes' => 'Joined queue from QR landing page.',
            ],
            [
                'key' => 'walkin-cash-waiting',
                'customer_email' => 'imane.b@example.test',
                'branch_code' => 'HQ-001',
                'service_code' => 'SRV-002',
                'ticket_number' => 1004,
                'ticket_source' => TicketSource::Reception,
                'ticket_status' => TicketStatus::Queued,
                'queue_status' => QueueEntryStatus::Waiting,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(9, 20),
                'checked_in_at' => null,
                'service_started_at' => null,
                'served_by' => null,
                'token_value' => 'QR-HQ-1004-DEMO',
                'token_status' => TokenStatus::Active,
                'token_used_at' => null,
                'check_in_result' => null,
                'kiosk_key' => null,
                'notes' => 'Cash deposit queue is building up.',
            ],
            [
                'key' => 'walkin-business-paused',
                'customer_email' => 'sofiane.r@example.test',
                'branch_code' => 'ALG-002',
                'service_code' => 'SRV-005',
                'ticket_number' => 2001,
                'ticket_source' => TicketSource::StaffAssisted,
                'ticket_status' => TicketStatus::Queued,
                'queue_status' => QueueEntryStatus::Waiting,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::Paused,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(10, 5),
                'checked_in_at' => null,
                'service_started_at' => null,
                'served_by' => null,
                'token_value' => 'QR-ALG-2001-DEMO',
                'token_status' => TokenStatus::Active,
                'token_used_at' => null,
                'check_in_result' => null,
                'kiosk_key' => null,
                'notes' => 'Business desk is temporarily paused.',
            ],
            [
                'key' => 'walkin-card-completed',
                'customer_email' => 'nadia.k@example.test',
                'branch_code' => 'ORN-003',
                'service_code' => 'SRV-004',
                'ticket_number' => 3001,
                'ticket_source' => TicketSource::Reception,
                'ticket_status' => TicketStatus::Completed,
                'queue_status' => QueueEntryStatus::Completed,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::ClosingSoon,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(8, 30),
                'checked_in_at' => today()->copy()->setTime(8, 35),
                'service_started_at' => today()->copy()->setTime(8, 40),
                'served_by' => 'samir.support@smartqdz.local',
                'token_value' => 'QR-ORN-3001-DEMO',
                'token_status' => TokenStatus::Consumed,
                'token_used_at' => today()->copy()->setTime(8, 35),
                'check_in_result' => CheckInResult::Success,
                'kiosk_key' => 'orn-side',
                'notes' => 'Completed earlier today.',
            ],
            [
                'key' => 'walkin-premium-cancelled',
                'customer_email' => 'farah.s@example.test',
                'branch_code' => 'HQ-001',
                'service_code' => 'SRV-003',
                'ticket_number' => 1005,
                'ticket_source' => TicketSource::Kiosk,
                'ticket_status' => TicketStatus::Escalated,
                'queue_status' => QueueEntryStatus::Cancelled,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(10, 10),
                'checked_in_at' => today()->copy()->setTime(10, 15),
                'service_started_at' => null,
                'served_by' => null,
                'token_value' => 'QR-HQ-1005-DEMO',
                'token_status' => TokenStatus::Expired,
                'token_used_at' => null,
                'check_in_result' => CheckInResult::ManualAssist,
                'kiosk_key' => 'hq-front',
                'notes' => 'Escalated after missing supporting documents.',
            ],
            [
                'key' => 'walkin-yesterday-completed',
                'customer_email' => 'adel.t@example.test',
                'branch_code' => 'HQ-001',
                'service_code' => 'SRV-001',
                'ticket_number' => 901,
                'ticket_source' => TicketSource::Reception,
                'ticket_status' => TicketStatus::Completed,
                'queue_status' => QueueEntryStatus::Completed,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today()->copy()->subDay(),
                'created_at' => today()->copy()->subDay()->setTime(9, 0),
                'checked_in_at' => today()->copy()->subDay()->setTime(9, 5),
                'service_started_at' => today()->copy()->subDay()->setTime(9, 12),
                'served_by' => 'amine.cashier@smartqdz.local',
                'token_value' => 'QR-HQ-0901-DEMO',
                'token_status' => TokenStatus::Consumed,
                'token_used_at' => today()->copy()->subDay()->setTime(9, 5),
                'check_in_result' => CheckInResult::Success,
                'kiosk_key' => 'hq-front',
                'notes' => 'Completed yesterday morning.',
            ],
            [
                'key' => 'walkin-two-days-completed',
                'customer_email' => 'malek.d@example.test',
                'branch_code' => 'ALG-002',
                'service_code' => 'SRV-005',
                'ticket_number' => 801,
                'ticket_source' => TicketSource::StaffAssisted,
                'ticket_status' => TicketStatus::Completed,
                'queue_status' => QueueEntryStatus::Completed,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today()->copy()->subDays(2),
                'created_at' => today()->copy()->subDays(2)->setTime(10, 0),
                'checked_in_at' => today()->copy()->subDays(2)->setTime(10, 8),
                'service_started_at' => today()->copy()->subDays(2)->setTime(10, 18),
                'served_by' => 'nadia.branch@smartqdz.local',
                'token_value' => 'QR-ALG-0801-DEMO',
                'token_status' => TokenStatus::Consumed,
                'token_used_at' => today()->copy()->subDays(2)->setTime(10, 8),
                'check_in_result' => CheckInResult::Success,
                'kiosk_key' => 'alg-business',
                'notes' => 'Completed during business rush.',
            ],
            [
                'key' => 'walkin-four-days-cancelled',
                'customer_email' => 'rania.z@example.test',
                'branch_code' => 'ORN-003',
                'service_code' => 'SRV-004',
                'ticket_number' => 701,
                'ticket_source' => TicketSource::QrScan,
                'ticket_status' => TicketStatus::Escalated,
                'queue_status' => QueueEntryStatus::Cancelled,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today()->copy()->subDays(4),
                'created_at' => today()->copy()->subDays(4)->setTime(11, 10),
                'checked_in_at' => null,
                'service_started_at' => null,
                'served_by' => null,
                'token_value' => 'QR-ORN-0701-DEMO',
                'token_status' => TokenStatus::Expired,
                'token_used_at' => null,
                'check_in_result' => null,
                'kiosk_key' => null,
                'notes' => 'Cancelled after service interruption.',
            ],
        ];

        foreach ($ticketDefinitions as $definition) {
            $session = DailyQueueSession::query()->updateOrCreate(
                [
                    'branch_id' => $branches[$definition['branch_code']]->getKey(),
                    'service_id' => $services[$definition['service_code']]->getKey(),
                    'session_date' => $definition['session_date']->toDateString(),
                ],
                [
                    'session_start_time' => '08:00:00',
                    'session_end_time' => '18:00:00',
                    'session_status' => $definition['session_status'],
                ]
            );

            $ticket = WalkInTicket::query()->updateOrCreate(
                [
                    'queue_session_id' => $session->getKey(),
                    'customer_id' => $customers[$definition['customer_email']]->getKey(),
                    'ticket_number' => $definition['ticket_number'],
                ],
                [
                    'branch_id' => $branches[$definition['branch_code']]->getKey(),
                    'service_id' => $services[$definition['service_code']]->getKey(),
                    'appointment_id' => null,
                    'ticket_source' => $definition['ticket_source'],
                    'ticket_status' => $definition['ticket_status'],
                    'notes' => $definition['notes'],
                ]
            );

            $this->setTimestamps(
                $ticket,
                $definition['created_at'],
                $definition['service_started_at'] ?? $definition['checked_in_at'] ?? $definition['created_at']->copy()->addMinutes(15)
            );

            $entry = QueueEntry::query()->updateOrCreate(
                ['ticket_id' => $ticket->getKey()],
                [
                    'queue_session_id' => $session->getKey(),
                    'customer_id' => $customers[$definition['customer_email']]->getKey(),
                    'queue_position' => $definition['queue_position'],
                    'queue_status' => $definition['queue_status'],
                    'checked_in_at' => $definition['checked_in_at'],
                    'service_started_at' => $definition['service_started_at'],
                    'served_by_staff_id' => $definition['served_by']
                        ? $staff[$definition['served_by']]['member']->getKey()
                        : null,
                ]
            );

            $this->setTimestamps(
                $entry,
                $definition['created_at'],
                $definition['service_started_at'] ?? $definition['checked_in_at'] ?? $definition['created_at']->copy()->addMinutes(20)
            );

            $token = QrCodeToken::query()->updateOrCreate(
                ['token_value' => $definition['token_value']],
                [
                    'ticket_id' => $ticket->getKey(),
                    'appointment_id' => null,
                    'expiration_date_time' => $definition['created_at']->copy()->addHours(8),
                    'used_date_time' => $definition['token_used_at'],
                    'token_status' => $definition['token_status'],
                ]
            );

            $this->setTimestamps($token, $definition['created_at'], $definition['token_used_at'] ?? $definition['created_at']);

            if ($definition['check_in_result'] !== null) {
                $record = CheckInRecord::query()->updateOrCreate(
                    ['qr_token_id' => $token->getKey()],
                    [
                        'kiosk_id' => $definition['kiosk_key'] ? $kiosks[$definition['kiosk_key']]->getKey() : null,
                        'customer_id' => $customers[$definition['customer_email']]->getKey(),
                        'check_in_date_time' => $definition['checked_in_at'] ?? $definition['created_at'],
                        'check_in_result' => $definition['check_in_result'],
                    ]
                );

                $this->setTimestamps(
                    $record,
                    $definition['checked_in_at'] ?? $definition['created_at'],
                    $definition['checked_in_at'] ?? $definition['created_at']
                );
            }
        }

        $this->seedAppointmentQueueEntries($branches, $services, $staff, $appointments);
    }

    protected function seedAppointmentQueueEntries(
        array $branches,
        array $services,
        array $staff,
        array $appointments,
    ): void {
        $definitions = [
            [
                'appointment_key' => 'appt-today-active',
                'branch_code' => 'HQ-001',
                'service_code' => 'SRV-001',
                'session_date' => today(),
                'session_status' => QueueSessionStatus::Live,
                'queue_position' => 4,
                'queue_status' => QueueEntryStatus::Waiting,
                'checked_in_at' => today()->copy()->setTime(9, 15),
                'service_started_at' => null,
                'served_by' => null,
                'created_at' => today()->copy()->setTime(9, 10),
            ],
            [
                'appointment_key' => 'appt-yesterday-noshow',
                'branch_code' => 'HQ-001',
                'service_code' => 'SRV-003',
                'session_date' => today()->copy()->subDay(),
                'session_status' => QueueSessionStatus::Live,
                'queue_position' => 1,
                'queue_status' => QueueEntryStatus::Cancelled,
                'checked_in_at' => null,
                'service_started_at' => null,
                'served_by' => null,
                'created_at' => today()->copy()->subDay()->setTime(8, 55),
            ],
            [
                'appointment_key' => 'appt-two-days-cancelled',
                'branch_code' => 'ALG-002',
                'service_code' => 'SRV-005',
                'session_date' => today()->copy()->subDays(2),
                'session_status' => QueueSessionStatus::Live,
                'queue_position' => 2,
                'queue_status' => QueueEntryStatus::Cancelled,
                'checked_in_at' => null,
                'service_started_at' => null,
                'served_by' => null,
                'created_at' => today()->copy()->subDays(2)->setTime(15, 20),
            ],
        ];

        foreach ($definitions as $definition) {
            $appointment = $appointments[$definition['appointment_key']];
            $session = DailyQueueSession::query()->updateOrCreate(
                [
                    'branch_id' => $branches[$definition['branch_code']]->getKey(),
                    'service_id' => $services[$definition['service_code']]->getKey(),
                    'session_date' => $definition['session_date']->toDateString(),
                ],
                [
                    'session_start_time' => '08:00:00',
                    'session_end_time' => '18:00:00',
                    'session_status' => $definition['session_status'],
                ]
            );

            $entry = QueueEntry::query()->updateOrCreate(
                ['appointment_id' => $appointment->getKey()],
                [
                    'queue_session_id' => $session->getKey(),
                    'customer_id' => $appointment->customer_id,
                    'queue_position' => $definition['queue_position'],
                    'queue_status' => $definition['queue_status'],
                    'checked_in_at' => $definition['checked_in_at'],
                    'service_started_at' => $definition['service_started_at'],
                    'served_by_staff_id' => $definition['served_by']
                        ? $staff[$definition['served_by']]['member']->getKey()
                        : null,
                    'ticket_id' => null,
                ]
            );

            $this->setTimestamps(
                $entry,
                $definition['created_at'],
                $definition['service_started_at'] ?? $definition['checked_in_at'] ?? $definition['created_at']->copy()->addMinutes(10)
            );
        }
    }

    protected function seedNotifications(User $user): void
    {
        $definitions = [
            [
                'notification_type' => 'queue_alert',
                'title' => 'Queue backlog at Downtown HQ',
                'description' => 'General Inquiry wait time exceeded 15 minutes.',
                'tone' => 'warning',
                'action_path' => '/dashboard/queue-monitor',
                'occurred_at' => now()->subMinutes(12),
                'read_at' => null,
            ],
            [
                'notification_type' => 'service_update',
                'title' => 'Business desk resumed',
                'description' => 'Bab Ezzouar Hub queue has resumed after a short pause.',
                'tone' => 'info',
                'action_path' => '/dashboard/services',
                'occurred_at' => now()->subMinutes(35),
                'read_at' => now()->subMinutes(10),
            ],
            [
                'notification_type' => 'vip_booking',
                'title' => 'Premium booking arriving tomorrow',
                'description' => 'A premium consultation booking is scheduled for tomorrow at 10:00.',
                'tone' => 'success',
                'action_path' => '/dashboard/appointments',
                'occurred_at' => now()->subHours(2),
                'read_at' => null,
            ],
            [
                'notification_type' => 'branch_status',
                'title' => 'Oran kiosk under maintenance',
                'description' => 'The Oran side kiosk is marked under maintenance.',
                'tone' => 'warning',
                'action_path' => '/dashboard/walk-ins',
                'occurred_at' => now()->subHours(4),
                'read_at' => null,
            ],
            [
                'notification_type' => 'daily_summary',
                'title' => 'Morning operations summary',
                'description' => 'Service throughput is stable across active branches.',
                'tone' => 'info',
                'action_path' => '/dashboard',
                'occurred_at' => now()->subHours(6),
                'read_at' => now()->subHours(5),
            ],
        ];

        foreach ($definitions as $definition) {
            $notification = Notification::query()->updateOrCreate(
                [
                    'user_id' => $user->getKey(),
                    'title' => $definition['title'],
                ],
                [
                    'notification_type' => $definition['notification_type'],
                    'description' => $definition['description'],
                    'tone' => $definition['tone'],
                    'action_path' => $definition['action_path'],
                    'occurred_at' => $definition['occurred_at'],
                    'notification_channel' => NotificationChannel::InApp,
                    'delivery_status' => NotificationDeliveryStatus::Sent,
                    'message_content' => $definition['description'],
                    'read_at' => $definition['read_at'],
                ]
            );

            $this->setTimestamps(
                $notification,
                $definition['occurred_at'],
                $definition['read_at'] ?? $definition['occurred_at']
            );
        }
    }

    protected function setTimestamps(Model $model, Carbon $createdAt, Carbon $updatedAt): void
    {
        $model->timestamps = false;
        $model->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ])->saveQuietly();
        $model->timestamps = true;
    }
}
