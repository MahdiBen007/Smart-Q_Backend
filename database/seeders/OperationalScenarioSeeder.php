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
use App\Models\JwtToken;
use App\Models\KioskDevice;
use App\Models\Notification;
use App\Models\PasswordResetToken;
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
use Illuminate\Support\Facades\Hash;

class OperationalScenarioSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $company = $this->seedCompany();
        $branches = $this->seedBranches($company);
        $services = $this->seedServices($branches);
        $staff = $this->seedStaff($company, $branches);
        $customers = $this->seedCustomers();

        $this->seedPreferences($staff);
        $kiosks = $this->seedKiosks($branches);
        $appointments = $this->seedAppointments($branches, $services, $staff, $customers);

        $this->seedAppointmentTokens($appointments, $customers, $kiosks);
        $this->seedWalkInsAndQueue($branches, $services, $staff, $customers, $appointments, $kiosks);
        $this->seedNotifications($staff);
        $this->seedAuthArtifacts($staff, $customers);
        $this->seedHighVolumeScenarios($branches, $services, $staff, $customers, $kiosks);
    }

    protected function seedCompany(): Company
    {
        return Company::query()->updateOrCreate(
            ['company_name' => 'SmartQ Operations'],
            ['company_status' => CompanyStatus::Active]
        );
    }

    protected function seedBranches(Company $company): array
    {
        $definitions = [
            [
                'key' => 'hq',
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
                'key' => 'alg',
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
                'key' => 'orn',
                'branch_code' => 'ORN-003',
                'branch_name' => 'Oran Service Point',
                'branch_address' => 'Front de Mer, Oran',
                'branch_status' => 'maintenance',
                'latitude' => 35.6981,
                'longitude' => -0.6348,
                'pin_top' => 67,
                'pin_left' => 24,
            ],
            [
                'key' => 'cst',
                'branch_code' => 'CST-004',
                'branch_name' => 'Constantine Advisory Center',
                'branch_address' => 'Business Towers, Constantine',
                'branch_status' => 'active',
                'latitude' => 36.3650,
                'longitude' => 6.6147,
                'pin_top' => 42,
                'pin_left' => 69,
            ],
            [
                'key' => 'bld',
                'branch_code' => 'BLD-005',
                'branch_name' => 'Blida Digital Desk',
                'branch_address' => 'New Service Park, Blida',
                'branch_status' => 'active',
                'latitude' => null,
                'longitude' => null,
                'pin_top' => null,
                'pin_left' => null,
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

            $branches[$definition['key']] = $branch;
        }

        return $branches;
    }

    protected function seedServices(array $branches): array
    {
        $definitions = [
            [
                'key' => 'inquiry',
                'service_code' => 'SRV-001',
                'service_name' => 'General Inquiry',
                'service_subtitle' => 'Everyday support desk',
                'service_description' => 'First-line support queue for account questions, document drop-off, and standard customer requests.',
                'service_icon' => 'support',
                'average_service_duration_minutes' => 10,
                'is_active' => true,
                'primary_branch_key' => 'hq',
                'branch_keys' => ['hq', 'alg', 'cst'],
            ],
            [
                'key' => 'cash',
                'service_code' => 'SRV-002',
                'service_name' => 'Cash Deposit',
                'service_subtitle' => 'Fast cash handling',
                'service_description' => 'Dedicated line for cash deposits, same-day payment support, and quick receipt issuance.',
                'service_icon' => 'cash',
                'average_service_duration_minutes' => 8,
                'is_active' => true,
                'primary_branch_key' => 'hq',
                'branch_keys' => ['hq', 'alg'],
            ],
            [
                'key' => 'premium',
                'service_code' => 'SRV-003',
                'service_name' => 'Premium Consultation',
                'service_subtitle' => 'High-value advisory',
                'service_description' => 'Reserved advisory flow for premium and enterprise clients who require portfolio reviews, escalation handling, and coordinated back-office follow-up across multiple teams.',
                'service_icon' => 'corporate',
                'average_service_duration_minutes' => 25,
                'is_active' => true,
                'primary_branch_key' => 'hq',
                'branch_keys' => ['hq', 'cst'],
            ],
            [
                'key' => 'card',
                'service_code' => 'SRV-004',
                'service_name' => 'Card Support',
                'service_subtitle' => 'Cards and replacements',
                'service_description' => 'Activation, renewal, replacement, and blocked-card assistance handled from front-office service counters.',
                'service_icon' => 'card',
                'average_service_duration_minutes' => 12,
                'is_active' => true,
                'primary_branch_key' => 'orn',
                'branch_keys' => ['hq', 'orn'],
            ],
            [
                'key' => 'business',
                'service_code' => 'SRV-005',
                'service_name' => 'Business Services',
                'service_subtitle' => 'SME operations desk',
                'service_description' => 'Operational queue for business customers, legal representatives, and SME account servicing requests.',
                'service_icon' => 'wallet',
                'average_service_duration_minutes' => 18,
                'is_active' => true,
                'primary_branch_key' => 'alg',
                'branch_keys' => ['alg', 'cst'],
            ],
            [
                'key' => 'onboarding',
                'service_code' => 'SRV-006',
                'service_name' => 'Account Opening',
                'service_subtitle' => 'Document-heavy onboarding',
                'service_description' => 'Long-form onboarding journey that collects identity proofs, compliance declarations, and branch approvals before account creation can be finalized.',
                'service_icon' => 'support',
                'average_service_duration_minutes' => 22,
                'is_active' => false,
                'primary_branch_key' => 'hq',
                'branch_keys' => ['hq', 'cst'],
            ],
            [
                'key' => 'digital',
                'service_code' => 'SRV-007',
                'service_name' => 'Digital Verification',
                'service_subtitle' => null,
                'service_description' => null,
                'service_icon' => 'support',
                'average_service_duration_minutes' => 14,
                'is_active' => true,
                'primary_branch_key' => 'cst',
                'branch_keys' => ['cst'],
            ],
        ];

        $services = [];

        foreach ($definitions as $definition) {
            $service = Service::query()->updateOrCreate(
                ['service_code' => $definition['service_code']],
                [
                    'branch_id' => $branches[$definition['primary_branch_key']]->getKey(),
                    'service_name' => $definition['service_name'],
                    'service_subtitle' => $definition['service_subtitle'],
                    'service_description' => $definition['service_description'],
                    'service_icon' => $definition['service_icon'],
                    'average_service_duration_minutes' => $definition['average_service_duration_minutes'],
                    'is_active' => $definition['is_active'],
                ]
            );

            $service->branches()->sync(
                collect($definition['branch_keys'])
                    ->map(fn (string $branchKey) => $branches[$branchKey]->getKey())
                    ->all()
            );

            $services[$definition['key']] = $service;
        }

        return $services;
    }

    protected function seedStaff(Company $company, array $branches): array
    {
        $definitions = [
            [
                'key' => 'mahdi_admin',
                'email' => 'Mahdi@smartq.com',
                'phone_number' => '+213500000100',
                'full_name' => 'Mahdi SmartQ',
                'display_staff_code' => 'STF-00000',
                'branch_key' => 'hq',
                'role' => UserRoleName::Admin,
                'employment_status' => EmploymentStatus::Active,
                'is_online' => true,
                'avatar_url' => null,
                'password' => '12345678',
            ],
            [
                'key' => 'admin',
                'email' => 'admin@smartq.app',
                'phone_number' => '+213500000101',
                'full_name' => 'Samir Ounissi',
                'display_staff_code' => 'STF-00001',
                'branch_key' => 'hq',
                'role' => UserRoleName::Admin,
                'employment_status' => EmploymentStatus::Active,
                'is_online' => true,
                'avatar_url' => 'https://assets.smartq.app/avatars/male-admin.png',
            ],
            [
                'key' => 'ops_manager',
                'email' => 'selma.operations@smartq.app',
                'phone_number' => '+213500000102',
                'full_name' => 'Selma Bensalem',
                'display_staff_code' => 'STF-00002',
                'branch_key' => 'hq',
                'role' => UserRoleName::Manager,
                'employment_status' => EmploymentStatus::Active,
                'is_online' => true,
                'avatar_url' => 'https://assets.smartq.app/avatars/female-operations.png',
            ],
            [
                'key' => 'hq_teller',
                'email' => 'amine.cherif@smartq.app',
                'phone_number' => '+213500000103',
                'full_name' => 'Amine Cherif',
                'display_staff_code' => 'STF-00003',
                'branch_key' => 'hq',
                'role' => UserRoleName::Staff,
                'employment_status' => EmploymentStatus::Active,
                'is_online' => true,
                'avatar_url' => 'https://assets.smartq.app/avatars/male-service.png',
            ],
            [
                'key' => 'alg_manager',
                'email' => 'yasmine.haddad@smartq.app',
                'phone_number' => '+213500000104',
                'full_name' => 'Yasmine Haddad',
                'display_staff_code' => 'STF-00004',
                'branch_key' => 'alg',
                'role' => UserRoleName::Manager,
                'employment_status' => EmploymentStatus::Active,
                'is_online' => true,
                'avatar_url' => 'https://assets.smartq.app/avatars/female-branch.png',
            ],
            [
                'key' => 'alg_desk',
                'email' => 'leila.mansouri@smartq.app',
                'phone_number' => '+213500000105',
                'full_name' => 'Leila Mansouri',
                'display_staff_code' => 'STF-00005',
                'branch_key' => 'alg',
                'role' => UserRoleName::Staff,
                'employment_status' => EmploymentStatus::Active,
                'is_online' => false,
                'avatar_url' => 'https://assets.smartq.app/avatars/female-desk.png',
            ],
            [
                'key' => 'oran_support',
                'email' => 'karim.bouali@smartq.app',
                'phone_number' => '+213500000106',
                'full_name' => 'Karim Bouali',
                'display_staff_code' => 'STF-00006',
                'branch_key' => 'orn',
                'role' => UserRoleName::Support,
                'employment_status' => EmploymentStatus::OnLeave,
                'is_online' => false,
                'avatar_url' => 'https://assets.smartq.app/avatars/male-support.png',
            ],
            [
                'key' => 'cst_manager',
                'email' => 'nora.benkacem@smartq.app',
                'phone_number' => '+213500000107',
                'full_name' => 'Nora Benkacem',
                'display_staff_code' => 'STF-00007',
                'branch_key' => 'cst',
                'role' => UserRoleName::Manager,
                'employment_status' => EmploymentStatus::Active,
                'is_online' => false,
                'avatar_url' => 'https://assets.smartq.app/avatars/female-manager.png',
            ],
            [
                'key' => 'cst_advisor',
                'email' => 'walid.kaci@smartq.app',
                'phone_number' => '+213500000108',
                'full_name' => 'Walid Kaci',
                'display_staff_code' => 'STF-00008',
                'branch_key' => 'cst',
                'role' => UserRoleName::Staff,
                'employment_status' => EmploymentStatus::Active,
                'is_online' => true,
                'avatar_url' => null,
            ],
            [
                'key' => 'moumen_staff',
                'email' => 'moumen@smartq.com',
                'phone_number' => '+213500000110',
                'full_name' => 'Moumen SmartQ',
                'display_staff_code' => 'STF-00010',
                'branch_key' => 'hq',
                'role' => UserRoleName::Staff,
                'employment_status' => EmploymentStatus::Active,
                'is_online' => true,
                'avatar_url' => null,
                'password' => '12345678',
            ],
            [
                'key' => 'cst_inactive',
                'email' => 'adel.ferrah@smartq.app',
                'phone_number' => '+213500000109',
                'full_name' => 'Adel Ferrah',
                'display_staff_code' => 'STF-00009',
                'branch_key' => 'cst',
                'role' => UserRoleName::Staff,
                'employment_status' => EmploymentStatus::Inactive,
                'is_online' => false,
                'avatar_url' => 'https://assets.smartq.app/avatars/male-archive.png',
            ],
        ];

        $staff = [];

        foreach ($definitions as $definition) {
            $branch = $branches[$definition['branch_key']];

            $user = User::query()->updateOrCreate(
                ['email' => $definition['email']],
                [
                    'phone_number' => $definition['phone_number'],
                    'password_hash' => Hash::make($definition['password'] ?? 'Password123!'),
                    'is_active' => $definition['employment_status'] !== EmploymentStatus::Inactive,
                ]
            );

            $this->syncRole($user, $definition['role']);

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
                    'last_active_at' => $definition['is_online']
                        ? now()->subMinutes(6)
                        : ($definition['employment_status'] === EmploymentStatus::Inactive ? now()->subDays(18) : now()->subHours(7)),
                ]
            );

            $staff[$definition['key']] = [
                'user' => $user,
                'member' => $member,
            ];
        }

        return $staff;
    }

    protected function seedCustomers(): array
    {
        $definitions = [
            [
                'key' => 'sara_amine',
                'full_name' => 'Sara Amine',
                'phone_number' => '+213555200001',
                'email_address' => 'sara.amine@clientmail.dz',
                'portal_user' => true,
                'portal_active' => true,
            ],
            [
                'key' => 'yousra_benali',
                'full_name' => 'Yousra Benali',
                'phone_number' => '+213555200002',
                'email_address' => 'yousra.benali@clientmail.dz',
                'portal_user' => true,
                'portal_active' => true,
            ],
            [
                'key' => 'ines_saad',
                'full_name' => 'Ines Saad',
                'phone_number' => '+213555200003',
                'email_address' => 'ines.saad@clientmail.dz',
                'portal_user' => true,
                'portal_active' => false,
            ],
            ['key' => 'karim_ait', 'full_name' => 'Karim Ait Ouali', 'phone_number' => '+213555200004', 'email_address' => 'karim.ait@northmail.dz'],
            ['key' => 'sabah_khellaf', 'full_name' => 'Sabah Khellaf', 'phone_number' => '+213555200005', 'email_address' => 'sabah.khellaf@northmail.dz'],
            ['key' => 'omar_benali', 'full_name' => 'Omar Benali', 'phone_number' => '+213555200006', 'email_address' => 'omar.benali@northmail.dz'],
            ['key' => 'lila_meziane', 'full_name' => 'Lila Meziane', 'phone_number' => '+213555200007', 'email_address' => 'lila.meziane@northmail.dz'],
            ['key' => 'yacine_henniche', 'full_name' => 'Yacine Henniche', 'phone_number' => '+213555200008', 'email_address' => 'yacine.henniche@northmail.dz'],
            ['key' => 'imane_boudiaf', 'full_name' => 'Imane Boudiaf', 'phone_number' => '+213555200009', 'email_address' => 'imane.boudiaf@northmail.dz'],
            ['key' => 'sofiane_rahmani', 'full_name' => 'Sofiane Rahmani', 'phone_number' => '+213555200010', 'email_address' => 'sofiane.rahmani@northmail.dz'],
            ['key' => 'nadia_kaci', 'full_name' => 'Nadia Kaci', 'phone_number' => '+213555200011', 'email_address' => 'nadia.kaci@northmail.dz'],
            ['key' => 'farah_sahraoui', 'full_name' => 'Farah Sahraoui', 'phone_number' => '+213555200012', 'email_address' => 'farah.sahraoui@northmail.dz'],
            ['key' => 'adel_toumi', 'full_name' => 'Adel Toumi', 'phone_number' => '+213555200013', 'email_address' => 'adel.toumi@northmail.dz'],
            ['key' => 'malek_dahmani', 'full_name' => 'Malek Dahmani', 'phone_number' => '+213555200014', 'email_address' => 'malek.dahmani@northmail.dz'],
            ['key' => 'rania_zerrouki', 'full_name' => 'Rania Zerrouki', 'phone_number' => '+213555200015', 'email_address' => 'rania.zerrouki@northmail.dz'],
            ['key' => 'meriem_long', 'full_name' => 'Meriem Benkhelifa Haddad', 'phone_number' => '+213555200016', 'email_address' => null],
            ['key' => 'nabil_short', 'full_name' => 'Nabil', 'phone_number' => '+213555200017', 'email_address' => 'nabil@northmail.dz'],
            ['key' => 'samia_corp', 'full_name' => 'Samia Aissaoui', 'phone_number' => '+213555200018', 'email_address' => 'samia.aissaoui@atlas-industries.dz'],
            ['key' => 'mourad_corp', 'full_name' => 'Mourad Khelifi', 'phone_number' => '+213555200019', 'email_address' => 'mourad.khelifi@atlas-industries.dz'],
        ];

        $customers = [];

        foreach ($definitions as $definition) {
            $userId = null;

            if (($definition['portal_user'] ?? false) === true) {
                $user = User::query()->updateOrCreate(
                    ['email' => $definition['email_address']],
                    [
                        'phone_number' => $definition['phone_number'],
                        'password_hash' => Hash::make('Password123!'),
                        'is_active' => (bool) ($definition['portal_active'] ?? true),
                    ]
                );

                $this->syncRole($user, UserRoleName::Customer);
                $userId = $user->getKey();
            }

            $customer = Customer::query()->updateOrCreate(
                ['phone_number' => $definition['phone_number']],
                [
                    'user_id' => $userId,
                    'full_name' => $definition['full_name'],
                    'email_address' => $definition['email_address'],
                ]
            );

            $customers[$definition['key']] = $customer;
        }

        return $customers;
    }

    protected function seedPreferences(array $staff): void
    {
        $definitions = [
            [
                'staff_key' => 'admin',
                'settings' => [
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
            ],
            [
                'staff_key' => 'ops_manager',
                'settings' => [
                    'appearance' => [
                        'theme' => 'light',
                        'language' => 'en',
                        'density' => 'comfortable',
                        'reducedMotion' => false,
                        'surfaceStyle' => 'glass',
                    ],
                    'workspace' => [
                        'sidebarCollapsed' => true,
                        'defaultBranchScope' => 'assigned',
                        'rememberFilters' => true,
                        'stickyDetailsPanel' => false,
                        'compactTables' => false,
                    ],
                    'notifications' => [
                        'queueAlerts' => true,
                        'serviceAlerts' => true,
                        'desktopNotifications' => false,
                        'soundEffects' => false,
                        'dailySummary' => true,
                    ],
                    'security' => [
                        'maskSensitiveData' => true,
                        'confirmDestructiveActions' => true,
                        'autoLockEnabled' => true,
                        'sessionTimeoutMinutes' => 30,
                    ],
                ],
            ],
            [
                'staff_key' => 'alg_manager',
                'settings' => [
                    'appearance' => [
                        'theme' => 'system',
                        'language' => 'ar',
                        'density' => 'compact',
                        'reducedMotion' => true,
                        'surfaceStyle' => 'glass',
                    ],
                    'workspace' => [
                        'sidebarCollapsed' => false,
                        'defaultBranchScope' => 'assigned',
                        'rememberFilters' => false,
                        'stickyDetailsPanel' => true,
                        'compactTables' => true,
                    ],
                    'notifications' => [
                        'queueAlerts' => true,
                        'serviceAlerts' => false,
                        'desktopNotifications' => true,
                        'soundEffects' => false,
                        'dailySummary' => false,
                    ],
                    'security' => [
                        'maskSensitiveData' => false,
                        'confirmDestructiveActions' => true,
                        'autoLockEnabled' => false,
                        'sessionTimeoutMinutes' => 20,
                    ],
                ],
            ],
        ];

        foreach ($definitions as $definition) {
            UserPreference::query()->updateOrCreate(
                ['user_id' => $staff[$definition['staff_key']]['user']->getKey()],
                ['dashboard_settings' => $definition['settings']]
            );
        }
    }

    protected function seedKiosks(array $branches): array
    {
        $definitions = [
            [
                'key' => 'hq_front',
                'branch_key' => 'hq',
                'device_identifier' => 'KSK-HQ-01',
                'device_location_description' => 'Main lobby entrance',
                'device_status' => DeviceStatus::Online,
            ],
            [
                'key' => 'hq_priority',
                'branch_key' => 'hq',
                'device_identifier' => 'KSK-HQ-02',
                'device_location_description' => 'Priority lane foyer',
                'device_status' => DeviceStatus::Busy,
            ],
            [
                'key' => 'alg_business',
                'branch_key' => 'alg',
                'device_identifier' => 'KSK-ALG-01',
                'device_location_description' => 'Business services hall',
                'device_status' => DeviceStatus::Busy,
            ],
            [
                'key' => 'orn_side',
                'branch_key' => 'orn',
                'device_identifier' => 'KSK-ORN-01',
                'device_location_description' => 'Secondary counter zone',
                'device_status' => DeviceStatus::Maintenance,
            ],
            [
                'key' => 'cst_quick',
                'branch_key' => 'cst',
                'device_identifier' => 'KSK-CST-01',
                'device_location_description' => 'Quick verification desk',
                'device_status' => DeviceStatus::Online,
            ],
        ];

        $kiosks = [];

        foreach ($definitions as $definition) {
            $kiosks[$definition['key']] = KioskDevice::query()->updateOrCreate(
                ['device_identifier' => $definition['device_identifier']],
                [
                    'branch_id' => $branches[$definition['branch_key']]->getKey(),
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
                'key' => 'today_active_hq',
                'customer_key' => 'sara_amine',
                'branch_key' => 'hq',
                'service_key' => 'inquiry',
                'staff_key' => 'ops_manager',
                'date' => today(),
                'time' => '09:30:00',
                'status' => AppointmentStatus::Active,
                'created_at' => now()->subHours(3),
            ],
            [
                'key' => 'today_confirmed_cash',
                'customer_key' => 'karim_ait',
                'branch_key' => 'hq',
                'service_key' => 'cash',
                'staff_key' => 'hq_teller',
                'date' => today(),
                'time' => '11:00:00',
                'status' => AppointmentStatus::Confirmed,
                'created_at' => now()->subDay()->setTime(15, 20),
            ],
            [
                'key' => 'today_pending_digital',
                'customer_key' => 'meriem_long',
                'branch_key' => 'cst',
                'service_key' => 'digital',
                'staff_key' => null,
                'date' => today(),
                'time' => '15:45:00',
                'status' => AppointmentStatus::Pending,
                'created_at' => now()->subHours(6),
            ],
            [
                'key' => 'tomorrow_premium',
                'customer_key' => 'samia_corp',
                'branch_key' => 'hq',
                'service_key' => 'premium',
                'staff_key' => 'ops_manager',
                'date' => today()->copy()->addDay(),
                'time' => '10:15:00',
                'status' => AppointmentStatus::Confirmed,
                'created_at' => now()->subHours(12),
            ],
            [
                'key' => 'tomorrow_business',
                'customer_key' => 'mourad_corp',
                'branch_key' => 'alg',
                'service_key' => 'business',
                'staff_key' => 'alg_manager',
                'date' => today()->copy()->addDay(),
                'time' => '13:30:00',
                'status' => AppointmentStatus::Confirmed,
                'created_at' => now()->subHours(20),
            ],
            [
                'key' => 'future_digital',
                'customer_key' => 'yousra_benali',
                'branch_key' => 'cst',
                'service_key' => 'digital',
                'staff_key' => 'cst_manager',
                'date' => today()->copy()->addDays(7),
                'time' => '14:10:00',
                'status' => AppointmentStatus::Pending,
                'created_at' => now()->subDays(2)->setTime(10, 40),
            ],
            [
                'key' => 'future_card_maintenance',
                'customer_key' => 'ines_saad',
                'branch_key' => 'orn',
                'service_key' => 'card',
                'staff_key' => null,
                'date' => today()->copy()->addDays(9),
                'time' => '09:20:00',
                'status' => AppointmentStatus::Confirmed,
                'created_at' => now()->subDays(1)->setTime(9, 15),
            ],
            [
                'key' => 'yesterday_no_show',
                'customer_key' => 'lila_meziane',
                'branch_key' => 'hq',
                'service_key' => 'premium',
                'staff_key' => 'ops_manager',
                'date' => today()->copy()->subDay(),
                'time' => '09:00:00',
                'status' => AppointmentStatus::NoShow,
                'created_at' => today()->copy()->subDays(3)->setTime(16, 5),
            ],
            [
                'key' => 'two_days_cancelled',
                'customer_key' => 'nadia_kaci',
                'branch_key' => 'alg',
                'service_key' => 'business',
                'staff_key' => 'alg_manager',
                'date' => today()->copy()->subDays(2),
                'time' => '15:30:00',
                'status' => AppointmentStatus::Cancelled,
                'created_at' => today()->copy()->subDays(4)->setTime(12, 45),
            ],
            [
                'key' => 'three_days_completed',
                'customer_key' => 'adel_toumi',
                'branch_key' => 'cst',
                'service_key' => 'premium',
                'staff_key' => 'cst_manager',
                'date' => today()->copy()->subDays(3),
                'time' => '11:10:00',
                'status' => AppointmentStatus::Confirmed,
                'created_at' => today()->copy()->subDays(5)->setTime(10, 20),
            ],
        ];

        $customerKeys = array_keys($customers);
        $rotatingScenarios = [
            ['branch_key' => 'hq', 'service_key' => 'inquiry', 'staff_key' => 'hq_teller', 'time' => '09:45:00'],
            ['branch_key' => 'alg', 'service_key' => 'cash', 'staff_key' => 'alg_manager', 'time' => '10:30:00'],
            ['branch_key' => 'cst', 'service_key' => 'business', 'staff_key' => 'cst_advisor', 'time' => '11:15:00'],
            ['branch_key' => 'orn', 'service_key' => 'card', 'staff_key' => null, 'time' => '14:00:00'],
            ['branch_key' => 'hq', 'service_key' => 'onboarding', 'staff_key' => 'ops_manager', 'time' => '15:20:00'],
            ['branch_key' => 'cst', 'service_key' => 'digital', 'staff_key' => null, 'time' => '16:10:00'],
        ];

        for ($offset = 4; $offset <= 26; $offset++) {
            $scenario = $rotatingScenarios[$offset % count($rotatingScenarios)];
            $status = match (true) {
                $offset % 11 === 0 => AppointmentStatus::Pending,
                $offset % 7 === 0 => AppointmentStatus::NoShow,
                $offset % 5 === 0 => AppointmentStatus::Cancelled,
                default => AppointmentStatus::Confirmed,
            };

            $definitions[] = [
                'key' => 'history_'.$offset,
                'customer_key' => $customerKeys[$offset % count($customerKeys)],
                'branch_key' => $scenario['branch_key'],
                'service_key' => $scenario['service_key'],
                'staff_key' => $status === AppointmentStatus::Pending ? null : $scenario['staff_key'],
                'date' => today()->copy()->subDays($offset),
                'time' => $scenario['time'],
                'status' => $status,
                'created_at' => today()->copy()->subDays($offset + 2)->setTime(9 + ($offset % 5), 10),
            ];
        }

        $appointments = [];

        foreach ($definitions as $definition) {
            $appointment = Appointment::query()->updateOrCreate(
                [
                    'customer_id' => $customers[$definition['customer_key']]->getKey(),
                    'branch_id' => $branches[$definition['branch_key']]->getKey(),
                    'service_id' => $services[$definition['service_key']]->getKey(),
                    'appointment_date' => $definition['date']->toDateString(),
                    'appointment_time' => $definition['time'],
                ],
                [
                    'staff_id' => $definition['staff_key'] ? $staff[$definition['staff_key']]['member']->getKey() : null,
                    'appointment_status' => $definition['status'],
                ]
            );

            $this->setTimestamps(
                $appointment,
                $definition['created_at'],
                $definition['date']->isFuture()
                    ? $definition['created_at']->copy()->addHours(2)
                    : min(now(), $definition['created_at']->copy()->addHours(6))
            );

            $appointments[$definition['key']] = $appointment;
        }

        return $appointments;
    }

    protected function seedAppointmentTokens(array $appointments, array $customers, array $kiosks): void
    {
        $definitions = [
            [
                'token_value' => 'APT-HQ-001-SARA',
                'appointment_key' => 'today_active_hq',
                'customer_key' => 'sara_amine',
                'token_status' => TokenStatus::Consumed,
                'used_at' => today()->copy()->setTime(9, 14),
                'expires_at' => today()->copy()->setTime(18, 0),
                'check_in_result' => CheckInResult::Success,
                'kiosk_key' => 'hq_front',
                'created_at' => today()->copy()->setTime(7, 50),
            ],
            [
                'token_value' => 'APT-HQ-002-SAMIA',
                'appointment_key' => 'tomorrow_premium',
                'customer_key' => 'samia_corp',
                'token_status' => TokenStatus::Active,
                'used_at' => null,
                'expires_at' => today()->copy()->addDay()->setTime(18, 0),
                'check_in_result' => null,
                'kiosk_key' => null,
                'created_at' => now()->subHours(8),
            ],
            [
                'token_value' => 'APT-ALG-003-MOURAD',
                'appointment_key' => 'tomorrow_business',
                'customer_key' => 'mourad_corp',
                'token_status' => TokenStatus::Active,
                'used_at' => null,
                'expires_at' => today()->copy()->addDay()->setTime(18, 0),
                'check_in_result' => null,
                'kiosk_key' => null,
                'created_at' => now()->subHours(10),
            ],
            [
                'token_value' => 'APT-HQ-004-LILA',
                'appointment_key' => 'yesterday_no_show',
                'customer_key' => 'lila_meziane',
                'token_status' => TokenStatus::Expired,
                'used_at' => null,
                'expires_at' => today()->copy()->subDay()->setTime(18, 0),
                'check_in_result' => null,
                'kiosk_key' => null,
                'created_at' => today()->copy()->subDays(2)->setTime(8, 45),
            ],
        ];

        foreach ($definitions as $definition) {
            $token = QrCodeToken::query()->updateOrCreate(
                ['token_value' => $definition['token_value']],
                [
                    'appointment_id' => $appointments[$definition['appointment_key']]->getKey(),
                    'ticket_id' => null,
                    'expiration_date_time' => $definition['expires_at'],
                    'used_date_time' => $definition['used_at'],
                    'token_status' => $definition['token_status'],
                ]
            );

            $this->setTimestamps($token, $definition['created_at'], $definition['used_at'] ?? $definition['created_at']);

            if ($definition['check_in_result'] !== null) {
                $record = CheckInRecord::query()->updateOrCreate(
                    ['qr_token_id' => $token->getKey()],
                    [
                        'kiosk_id' => $definition['kiosk_key'] ? $kiosks[$definition['kiosk_key']]->getKey() : null,
                        'customer_id' => $customers[$definition['customer_key']]->getKey(),
                        'check_in_date_time' => $definition['used_at'] ?? $definition['created_at'],
                        'check_in_result' => $definition['check_in_result'],
                    ]
                );

                $this->setTimestamps($record, $definition['used_at'] ?? $definition['created_at'], $definition['used_at'] ?? $definition['created_at']);
            }
        }
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
                'key' => 'hq_inquiry_serving',
                'customer_key' => 'omar_benali',
                'branch_key' => 'hq',
                'service_key' => 'inquiry',
                'ticket_number' => 1001,
                'ticket_source' => TicketSource::Reception,
                'ticket_status' => TicketStatus::Serving,
                'queue_status' => QueueEntryStatus::Serving,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(8, 35),
                'checked_in_at' => today()->copy()->setTime(8, 41),
                'service_started_at' => today()->copy()->setTime(8, 50),
                'served_by_key' => 'hq_teller',
                'token_value' => 'QR-HQ-1001-OMAR',
                'token_status' => TokenStatus::Consumed,
                'token_used_at' => today()->copy()->setTime(8, 41),
                'check_in_result' => CheckInResult::Success,
                'kiosk_key' => 'hq_front',
                'appointment_key' => null,
                'notes' => 'Priority client currently at the counter.',
            ],
            [
                'key' => 'hq_inquiry_next',
                'customer_key' => 'lila_meziane',
                'branch_key' => 'hq',
                'service_key' => 'inquiry',
                'ticket_number' => 1002,
                'ticket_source' => TicketSource::Kiosk,
                'ticket_status' => TicketStatus::CheckedIn,
                'queue_status' => QueueEntryStatus::Next,
                'queue_position' => 2,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(8, 52),
                'checked_in_at' => today()->copy()->setTime(9, 0),
                'service_started_at' => null,
                'served_by_key' => null,
                'token_value' => 'QR-HQ-1002-LILA',
                'token_status' => TokenStatus::Consumed,
                'token_used_at' => today()->copy()->setTime(9, 0),
                'check_in_result' => CheckInResult::Success,
                'kiosk_key' => 'hq_priority',
                'appointment_key' => null,
                'notes' => 'Checked in and ready for the next available desk.',
            ],
            [
                'key' => 'hq_inquiry_waiting',
                'customer_key' => 'yacine_henniche',
                'branch_key' => 'hq',
                'service_key' => 'inquiry',
                'ticket_number' => 1003,
                'ticket_source' => TicketSource::QrScan,
                'ticket_status' => TicketStatus::Queued,
                'queue_status' => QueueEntryStatus::Waiting,
                'queue_position' => 3,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(9, 6),
                'checked_in_at' => null,
                'service_started_at' => null,
                'served_by_key' => null,
                'token_value' => 'QR-HQ-1003-YACINE',
                'token_status' => TokenStatus::Active,
                'token_used_at' => null,
                'check_in_result' => null,
                'kiosk_key' => null,
                'appointment_key' => null,
                'notes' => 'Joined from the QR landing page after checking branch availability on mobile.',
            ],
            [
                'key' => 'hq_cash_waiting',
                'customer_key' => 'imane_boudiaf',
                'branch_key' => 'hq',
                'service_key' => 'cash',
                'ticket_number' => 1101,
                'ticket_source' => TicketSource::Reception,
                'ticket_status' => TicketStatus::Queued,
                'queue_status' => QueueEntryStatus::Waiting,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(9, 18),
                'checked_in_at' => null,
                'service_started_at' => null,
                'served_by_key' => null,
                'token_value' => 'QR-HQ-1101-IMANE',
                'token_status' => TokenStatus::Active,
                'token_used_at' => null,
                'check_in_result' => null,
                'kiosk_key' => null,
                'appointment_key' => null,
                'notes' => 'Deposit line is growing after the lunch cash run.',
            ],
            [
                'key' => 'hq_cash_pending',
                'customer_key' => 'meriem_long',
                'branch_key' => 'hq',
                'service_key' => 'cash',
                'ticket_number' => 1102,
                'ticket_source' => TicketSource::Kiosk,
                'ticket_status' => TicketStatus::Queued,
                'queue_status' => QueueEntryStatus::Waiting,
                'queue_position' => 2,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(9, 26),
                'checked_in_at' => null,
                'service_started_at' => null,
                'served_by_key' => null,
                'token_value' => 'QR-HQ-1102-MERIEM',
                'token_status' => TokenStatus::Active,
                'token_used_at' => null,
                'check_in_result' => CheckInResult::Pending,
                'kiosk_key' => 'hq_front',
                'appointment_key' => null,
                'notes' => 'Identity check is still pending because the deposit slip is missing a branch stamp.',
            ],
            [
                'key' => 'alg_business_paused',
                'customer_key' => 'sofiane_rahmani',
                'branch_key' => 'alg',
                'service_key' => 'business',
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
                'served_by_key' => null,
                'token_value' => 'QR-ALG-2001-SOFIANE',
                'token_status' => TokenStatus::Active,
                'token_used_at' => null,
                'check_in_result' => null,
                'kiosk_key' => null,
                'appointment_key' => null,
                'notes' => 'Business desk is paused while supporting documents are being reviewed by operations.',
            ],
            [
                'key' => 'cst_digital_waiting',
                'customer_key' => 'nabil_short',
                'branch_key' => 'cst',
                'service_key' => 'digital',
                'ticket_number' => 4001,
                'ticket_source' => TicketSource::QrScan,
                'ticket_status' => TicketStatus::Queued,
                'queue_status' => QueueEntryStatus::Waiting,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(9, 45),
                'checked_in_at' => null,
                'service_started_at' => null,
                'served_by_key' => null,
                'token_value' => 'QR-CST-4001-NABIL',
                'token_status' => TokenStatus::Active,
                'token_used_at' => null,
                'check_in_result' => null,
                'kiosk_key' => null,
                'appointment_key' => null,
                'notes' => null,
            ],
            [
                'key' => 'cst_business_completed',
                'customer_key' => 'samia_corp',
                'branch_key' => 'cst',
                'service_key' => 'business',
                'ticket_number' => 4101,
                'ticket_source' => TicketSource::Reception,
                'ticket_status' => TicketStatus::Completed,
                'queue_status' => QueueEntryStatus::Completed,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(8, 15),
                'checked_in_at' => today()->copy()->setTime(8, 18),
                'service_started_at' => today()->copy()->setTime(8, 26),
                'served_by_key' => 'cst_advisor',
                'token_value' => 'QR-CST-4101-SAMIA',
                'token_status' => TokenStatus::Consumed,
                'token_used_at' => today()->copy()->setTime(8, 18),
                'check_in_result' => CheckInResult::Success,
                'kiosk_key' => 'cst_quick',
                'appointment_key' => null,
                'notes' => 'Corporate file validated and completed before noon.',
            ],
            [
                'key' => 'orn_card_completed',
                'customer_key' => 'nadia_kaci',
                'branch_key' => 'orn',
                'service_key' => 'card',
                'ticket_number' => 3001,
                'ticket_source' => TicketSource::Reception,
                'ticket_status' => TicketStatus::Completed,
                'queue_status' => QueueEntryStatus::Completed,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::ClosingSoon,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(8, 25),
                'checked_in_at' => today()->copy()->setTime(8, 31),
                'service_started_at' => today()->copy()->setTime(8, 39),
                'served_by_key' => 'oran_support',
                'token_value' => 'QR-ORN-3001-NADIA',
                'token_status' => TokenStatus::Consumed,
                'token_used_at' => today()->copy()->setTime(8, 31),
                'check_in_result' => CheckInResult::Success,
                'kiosk_key' => 'orn_side',
                'appointment_key' => null,
                'notes' => 'Completed before the maintenance team resumed work on the side kiosk.',
            ],
            [
                'key' => 'hq_premium_escalated',
                'customer_key' => 'farah_sahraoui',
                'branch_key' => 'hq',
                'service_key' => 'premium',
                'ticket_number' => 1201,
                'ticket_source' => TicketSource::Kiosk,
                'ticket_status' => TicketStatus::Escalated,
                'queue_status' => QueueEntryStatus::Cancelled,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today(),
                'created_at' => today()->copy()->setTime(10, 12),
                'checked_in_at' => today()->copy()->setTime(10, 18),
                'service_started_at' => null,
                'served_by_key' => null,
                'token_value' => 'QR-HQ-1201-FARAH',
                'token_status' => TokenStatus::Expired,
                'token_used_at' => null,
                'check_in_result' => CheckInResult::ManualAssist,
                'kiosk_key' => 'hq_priority',
                'appointment_key' => 'today_confirmed_cash',
                'notes' => 'Escalated after missing compliance attachments and a mismatch between the client profile and the corporate authorization file.',
            ],
        ];

        $customerKeys = array_keys($customers);
        $historicalScenarios = [
            ['branch_key' => 'hq', 'service_key' => 'inquiry', 'served_by_key' => 'hq_teller', 'base_ticket' => 800],
            ['branch_key' => 'alg', 'service_key' => 'business', 'served_by_key' => 'alg_manager', 'base_ticket' => 1800],
            ['branch_key' => 'cst', 'service_key' => 'premium', 'served_by_key' => 'cst_manager', 'base_ticket' => 2800],
            ['branch_key' => 'orn', 'service_key' => 'card', 'served_by_key' => 'oran_support', 'base_ticket' => 3800],
        ];
        $sources = [TicketSource::Reception, TicketSource::Kiosk, TicketSource::QrScan, TicketSource::StaffAssisted];

        for ($offset = 2; $offset <= 20; $offset++) {
            $scenario = $historicalScenarios[$offset % count($historicalScenarios)];
            $isCancelled = $offset % 5 === 0;
            $createdAt = today()->copy()->subDays($offset)->setTime(9 + ($offset % 4), 10);
            $checkedInAt = $isCancelled ? null : $createdAt->copy()->addMinutes(5);
            $serviceStartedAt = $isCancelled ? null : $createdAt->copy()->addMinutes(12);

            $ticketDefinitions[] = [
                'key' => 'history_walkin_'.$offset,
                'customer_key' => $customerKeys[($offset + 3) % count($customerKeys)],
                'branch_key' => $scenario['branch_key'],
                'service_key' => $scenario['service_key'],
                'ticket_number' => $scenario['base_ticket'] + $offset,
                'ticket_source' => $sources[$offset % count($sources)],
                'ticket_status' => $isCancelled ? TicketStatus::Escalated : TicketStatus::Completed,
                'queue_status' => $isCancelled ? QueueEntryStatus::Cancelled : QueueEntryStatus::Completed,
                'queue_position' => 1,
                'session_status' => QueueSessionStatus::Live,
                'session_date' => today()->copy()->subDays($offset),
                'created_at' => $createdAt,
                'checked_in_at' => $checkedInAt,
                'service_started_at' => $serviceStartedAt,
                'served_by_key' => $isCancelled ? null : $scenario['served_by_key'],
                'token_value' => sprintf('QR-%s-%04d-%02d', strtoupper($scenario['branch_key']), $scenario['base_ticket'] + $offset, $offset),
                'token_status' => $isCancelled ? TokenStatus::Expired : TokenStatus::Consumed,
                'token_used_at' => $checkedInAt,
                'check_in_result' => $isCancelled ? null : CheckInResult::Success,
                'kiosk_key' => $scenario['branch_key'] === 'orn' ? 'orn_side' : ($scenario['branch_key'] === 'cst' ? 'cst_quick' : ($scenario['branch_key'] === 'alg' ? 'alg_business' : 'hq_front')),
                'appointment_key' => null,
                'notes' => $isCancelled ? 'Customer left the branch before final verification was completed.' : null,
            ];
        }

        foreach ($ticketDefinitions as $definition) {
            $session = DailyQueueSession::query()->updateOrCreate(
                [
                    'branch_id' => $branches[$definition['branch_key']]->getKey(),
                    'service_id' => $services[$definition['service_key']]->getKey(),
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
                    'branch_id' => $branches[$definition['branch_key']]->getKey(),
                    'ticket_number' => $definition['ticket_number'],
                ],
                [
                    'customer_id' => $customers[$definition['customer_key']]->getKey(),
                    'service_id' => $services[$definition['service_key']]->getKey(),
                    'queue_session_id' => $session->getKey(),
                    'appointment_id' => $definition['appointment_key'] ? $appointments[$definition['appointment_key']]->getKey() : null,
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
                    'customer_id' => $customers[$definition['customer_key']]->getKey(),
                    'queue_position' => $definition['queue_position'],
                    'queue_status' => $definition['queue_status'],
                    'checked_in_at' => $definition['checked_in_at'],
                    'service_started_at' => $definition['service_started_at'],
                    'served_by_staff_id' => $definition['served_by_key'] ? $staff[$definition['served_by_key']]['member']->getKey() : null,
                    'appointment_id' => null,
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
                        'customer_id' => $customers[$definition['customer_key']]->getKey(),
                        'check_in_date_time' => $definition['checked_in_at'] ?? $definition['created_at'],
                        'check_in_result' => $definition['check_in_result'],
                    ]
                );

                $this->setTimestamps($record, $definition['checked_in_at'] ?? $definition['created_at'], $definition['checked_in_at'] ?? $definition['created_at']);
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
                'appointment_key' => 'today_active_hq',
                'branch_key' => 'hq',
                'service_key' => 'inquiry',
                'session_date' => today(),
                'session_status' => QueueSessionStatus::Live,
                'queue_position' => 4,
                'queue_status' => QueueEntryStatus::Waiting,
                'checked_in_at' => today()->copy()->setTime(9, 15),
                'service_started_at' => null,
                'served_by_key' => null,
                'created_at' => today()->copy()->setTime(9, 10),
            ],
            [
                'appointment_key' => 'yesterday_no_show',
                'branch_key' => 'hq',
                'service_key' => 'premium',
                'session_date' => today()->copy()->subDay(),
                'session_status' => QueueSessionStatus::Live,
                'queue_position' => 1,
                'queue_status' => QueueEntryStatus::Cancelled,
                'checked_in_at' => null,
                'service_started_at' => null,
                'served_by_key' => null,
                'created_at' => today()->copy()->subDay()->setTime(8, 55),
            ],
            [
                'appointment_key' => 'two_days_cancelled',
                'branch_key' => 'alg',
                'service_key' => 'business',
                'session_date' => today()->copy()->subDays(2),
                'session_status' => QueueSessionStatus::Live,
                'queue_position' => 1,
                'queue_status' => QueueEntryStatus::Cancelled,
                'checked_in_at' => null,
                'service_started_at' => null,
                'served_by_key' => null,
                'created_at' => today()->copy()->subDays(2)->setTime(15, 18),
            ],
            [
                'appointment_key' => 'three_days_completed',
                'branch_key' => 'cst',
                'service_key' => 'premium',
                'session_date' => today()->copy()->subDays(3),
                'session_status' => QueueSessionStatus::Live,
                'queue_position' => 1,
                'queue_status' => QueueEntryStatus::Completed,
                'checked_in_at' => today()->copy()->subDays(3)->setTime(11, 2),
                'service_started_at' => today()->copy()->subDays(3)->setTime(11, 12),
                'served_by_key' => 'cst_manager',
                'created_at' => today()->copy()->subDays(3)->setTime(10, 56),
            ],
        ];

        foreach ($definitions as $definition) {
            $appointment = $appointments[$definition['appointment_key']];
            $session = DailyQueueSession::query()->updateOrCreate(
                [
                    'branch_id' => $branches[$definition['branch_key']]->getKey(),
                    'service_id' => $services[$definition['service_key']]->getKey(),
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
                    'served_by_staff_id' => $definition['served_by_key'] ? $staff[$definition['served_by_key']]['member']->getKey() : null,
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

    protected function seedNotifications(array $staff): void
    {
        $definitions = [
            [
                'user_key' => 'admin',
                'notification_type' => 'queue_alert',
                'title' => 'Queue pressure at Downtown HQ',
                'description' => 'General Inquiry exceeded the 15 minute wait threshold.',
                'message_content' => 'General Inquiry exceeded the 15 minute wait threshold.',
                'tone' => 'critical',
                'action_path' => '/dashboard/queue-monitor',
                'occurred_at' => now()->subMinutes(8),
                'read_at' => null,
                'notification_channel' => NotificationChannel::InApp,
                'delivery_status' => NotificationDeliveryStatus::Sent,
            ],
            [
                'user_key' => 'admin',
                'notification_type' => 'service_update',
                'title' => 'Business desk resumed',
                'description' => 'Bab Ezzouar business flow resumed after compliance review.',
                'message_content' => 'Bab Ezzouar business flow resumed after compliance review.',
                'tone' => 'warning',
                'action_path' => '/dashboard/services',
                'occurred_at' => now()->subMinutes(24),
                'read_at' => now()->subMinutes(10),
                'notification_channel' => NotificationChannel::Push,
                'delivery_status' => NotificationDeliveryStatus::Sent,
            ],
            [
                'user_key' => 'admin',
                'notification_type' => 'booking',
                'title' => 'Premium client arriving tomorrow',
                'description' => 'A corporate advisory appointment is confirmed for tomorrow at 10:15.',
                'message_content' => 'A corporate advisory appointment is confirmed for tomorrow at 10:15.',
                'tone' => 'success',
                'action_path' => '/dashboard/appointments',
                'occurred_at' => now()->subHours(2),
                'read_at' => null,
                'notification_channel' => NotificationChannel::Email,
                'delivery_status' => NotificationDeliveryStatus::Sent,
            ],
            [
                'user_key' => 'admin',
                'notification_type' => 'branch_status',
                'title' => 'Oran kiosk under maintenance',
                'description' => 'The secondary kiosk remains in maintenance mode until the evening slot.',
                'message_content' => 'The secondary kiosk remains in maintenance mode until the evening slot.',
                'tone' => 'warning',
                'action_path' => '/dashboard/walk-ins',
                'occurred_at' => now()->subHours(3),
                'read_at' => null,
                'notification_channel' => NotificationChannel::Sms,
                'delivery_status' => NotificationDeliveryStatus::Pending,
            ],
            [
                'user_key' => 'admin',
                'notification_type' => 'security',
                'title' => null,
                'description' => null,
                'message_content' => 'A password reset request was opened from a new browser session.',
                'tone' => 'info',
                'action_path' => '/dashboard/settings',
                'occurred_at' => now()->subHours(6),
                'read_at' => now()->subHours(4),
                'notification_channel' => NotificationChannel::Email,
                'delivery_status' => NotificationDeliveryStatus::Failed,
            ],
            [
                'user_key' => 'admin',
                'notification_type' => 'daily_summary',
                'title' => 'Morning operations summary',
                'description' => 'Completed service count is holding above the weekly baseline.',
                'message_content' => 'Completed service count is holding above the weekly baseline.',
                'tone' => 'info',
                'action_path' => '/dashboard',
                'occurred_at' => now()->subHours(9),
                'read_at' => now()->subHours(8),
                'notification_channel' => NotificationChannel::InApp,
                'delivery_status' => NotificationDeliveryStatus::Sent,
            ],
            [
                'user_key' => 'admin',
                'notification_type' => 'staffing',
                'title' => 'One advisor marked inactive',
                'description' => 'The archive desk profile in Constantine is inactive and no longer available for assignment.',
                'message_content' => 'The archive desk profile in Constantine is inactive and no longer available for assignment.',
                'tone' => 'warning',
                'action_path' => '/dashboard/staff',
                'occurred_at' => now()->subHours(14),
                'read_at' => null,
                'notification_channel' => NotificationChannel::InApp,
                'delivery_status' => NotificationDeliveryStatus::Sent,
            ],
            [
                'user_key' => 'admin',
                'notification_type' => 'sync',
                'title' => 'Service catalog synchronized',
                'description' => 'Branch coverage and service metadata are now aligned across active desks.',
                'message_content' => 'Branch coverage and service metadata are now aligned across active desks.',
                'tone' => 'success',
                'action_path' => '/dashboard/services',
                'occurred_at' => now()->subDay(),
                'read_at' => now()->subHours(18),
                'notification_channel' => NotificationChannel::Push,
                'delivery_status' => NotificationDeliveryStatus::Sent,
            ],
            [
                'user_key' => 'ops_manager',
                'notification_type' => 'queue_alert',
                'title' => 'Two tickets are waiting for manual validation',
                'description' => 'Cash handling queue still contains one record with pending compliance checks.',
                'message_content' => 'Cash handling queue still contains one record with pending compliance checks.',
                'tone' => 'warning',
                'action_path' => '/dashboard/walk-ins',
                'occurred_at' => now()->subMinutes(35),
                'read_at' => null,
                'notification_channel' => NotificationChannel::InApp,
                'delivery_status' => NotificationDeliveryStatus::Sent,
            ],
            [
                'user_key' => 'alg_manager',
                'notification_type' => 'branch_status',
                'title' => 'Paused business queue requires review',
                'description' => 'One business-services session is still paused pending manager approval.',
                'message_content' => 'One business-services session is still paused pending manager approval.',
                'tone' => 'critical',
                'action_path' => '/dashboard/queue-monitor',
                'occurred_at' => now()->subMinutes(55),
                'read_at' => null,
                'notification_channel' => NotificationChannel::InApp,
                'delivery_status' => NotificationDeliveryStatus::Sent,
            ],
        ];

        foreach ($definitions as $definition) {
            $notification = Notification::query()->updateOrCreate(
                [
                    'user_id' => $staff[$definition['user_key']]['user']->getKey(),
                    'notification_type' => $definition['notification_type'],
                    'occurred_at' => $definition['occurred_at'],
                ],
                [
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'tone' => $definition['tone'],
                    'action_path' => $definition['action_path'],
                    'notification_channel' => $definition['notification_channel'],
                    'delivery_status' => $definition['delivery_status'],
                    'message_content' => $definition['message_content'],
                    'read_at' => $definition['read_at'],
                ]
            );

            $this->setTimestamps($notification, $definition['occurred_at'], $definition['read_at'] ?? $definition['occurred_at']);
        }
    }

    protected function seedAuthArtifacts(array $staff, array $customers): void
    {
        $jwtDefinitions = [
            [
                'jti' => 'smartq-admin-access',
                'user_id' => $staff['admin']['user']->getKey(),
                'token_type' => 'access',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0 SmartQ Admin Dashboard',
                'last_used_at' => now()->subMinutes(3),
                'expires_at' => now()->addHours(6),
                'revoked_at' => null,
                'created_at' => now()->subHours(4),
            ],
            [
                'jti' => 'smartq-admin-refresh',
                'user_id' => $staff['admin']['user']->getKey(),
                'token_type' => 'refresh',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0 SmartQ Admin Dashboard',
                'last_used_at' => now()->subMinutes(30),
                'expires_at' => now()->addDays(7),
                'revoked_at' => null,
                'created_at' => now()->subDays(1),
            ],
            [
                'jti' => 'smartq-alg-revoked',
                'user_id' => $staff['alg_manager']['user']->getKey(),
                'token_type' => 'access',
                'ip_address' => '10.0.10.24',
                'user_agent' => 'Chrome / Branch Workstation',
                'last_used_at' => now()->subDays(2),
                'expires_at' => now()->subHours(6),
                'revoked_at' => now()->subDay(),
                'created_at' => now()->subDays(3),
            ],
            [
                'jti' => 'smartq-customer-portal',
                'user_id' => $customers['sara_amine']->user_id,
                'token_type' => 'access',
                'ip_address' => '196.25.14.8',
                'user_agent' => 'Safari / Customer Portal',
                'last_used_at' => now()->subHours(5),
                'expires_at' => now()->addHours(8),
                'revoked_at' => null,
                'created_at' => now()->subHours(9),
            ],
        ];

        foreach ($jwtDefinitions as $definition) {
            if (! $definition['user_id']) {
                continue;
            }

            $token = JwtToken::query()->updateOrCreate(
                ['jti' => $definition['jti']],
                [
                    'user_id' => $definition['user_id'],
                    'token_type' => $definition['token_type'],
                    'ip_address' => $definition['ip_address'],
                    'user_agent' => $definition['user_agent'],
                    'last_used_at' => $definition['last_used_at'],
                    'expires_at' => $definition['expires_at'],
                    'revoked_at' => $definition['revoked_at'],
                ]
            );

            $this->setTimestamps($token, $definition['created_at'], $definition['last_used_at'] ?? $definition['created_at']);
        }

        $resetDefinitions = [
            [
                'user_id' => $staff['admin']['user']->getKey(),
                'token_hash' => hash('sha256', 'admin-reset-open'),
                'expires_at' => now()->addHours(2),
                'used_at' => null,
                'created_at' => now()->subMinutes(20),
            ],
            [
                'user_id' => $staff['cst_inactive']['user']->getKey(),
                'token_hash' => hash('sha256', 'inactive-reset-used'),
                'expires_at' => now()->subHours(10),
                'used_at' => now()->subHours(11),
                'created_at' => now()->subHours(12),
            ],
            [
                'user_id' => $customers['yousra_benali']->user_id,
                'token_hash' => hash('sha256', 'customer-reset-expired'),
                'expires_at' => now()->subHours(1),
                'used_at' => null,
                'created_at' => now()->subHours(4),
            ],
        ];

        foreach ($resetDefinitions as $definition) {
            if (! $definition['user_id']) {
                continue;
            }

            $token = PasswordResetToken::query()->updateOrCreate(
                [
                    'user_id' => $definition['user_id'],
                    'token_hash' => $definition['token_hash'],
                ],
                [
                    'expires_at' => $definition['expires_at'],
                    'used_at' => $definition['used_at'],
                ]
            );

            $this->setTimestamps($token, $definition['created_at'], $definition['used_at'] ?? $definition['created_at']);
        }
    }

    protected function seedHighVolumeScenarios(
        array $branches,
        array $services,
        array $staff,
        array $customers,
        array $kiosks,
    ): void {
        $loadCustomers = $this->seedHighVolumeCustomers();
        $allCustomers = array_merge($customers, $loadCustomers);

        $this->seedHighVolumeAppointments($branches, $services, $staff, $allCustomers);
        $this->seedHighVolumeWalkInsAndQueue($branches, $services, $staff, $allCustomers, $kiosks);
        $this->seedHighVolumeNotifications($staff);
    }

    protected function seedHighVolumeCustomers(): array
    {
        $scale = $this->loadScale();
        $firstNames = [
            'Amina', 'Yacine', 'Sofiane', 'Meriem', 'Nassim', 'Lina', 'Walid', 'Imane',
            'Adel', 'Farah', 'Nadia', 'Karim', 'Rania', 'Samir', 'Leila', 'Mourad',
        ];
        $lastNames = [
            'Bensaid', 'Khelifi', 'Mansouri', 'Bouguerra', 'Hamdi', 'Saadi', 'Ferhat', 'Toumi',
            'Benkacem', 'Ait Ali', 'Rahmani', 'Cherif', 'Boudiaf', 'Kaci', 'Benali', 'Sahraoui',
        ];

        $customers = [];

        for ($index = 1; $index <= (160 * $scale); $index++) {
            $key = sprintf('load_customer_%03d', $index);
            $firstName = $firstNames[$index % count($firstNames)];
            $lastName = $lastNames[($index * 3) % count($lastNames)];
            $fullName = sprintf('%s %s', $firstName, $lastName);
            $phoneNumber = sprintf('+213556%06d', $index);
            $email = $index % 5 === 0
                ? null
                : sprintf('load.customer.%03d@smartq-load.test', $index);
            $portalUser = $index % 9 === 0 && $email !== null;
            $userId = null;

            if ($portalUser) {
                $user = User::query()->updateOrCreate(
                    ['email' => $email],
                    [
                        'phone_number' => $phoneNumber,
                        'password_hash' => Hash::make('Password123!'),
                        'is_active' => $index % 18 !== 0,
                    ]
                );

                $this->syncRole($user, UserRoleName::Customer);
                $userId = $user->getKey();
            }

            $customer = Customer::query()->updateOrCreate(
                ['phone_number' => $phoneNumber],
                [
                    'user_id' => $userId,
                    'full_name' => $fullName,
                    'email_address' => $email,
                ]
            );

            $customers[$key] = $customer;
        }

        return $customers;
    }

    protected function seedHighVolumeAppointments(
        array $branches,
        array $services,
        array $staff,
        array $customers,
    ): void {
        $scale = $this->loadScale();
        $customerKeys = array_keys($customers);
        $scenarios = [
            ['branch_key' => 'hq', 'service_key' => 'inquiry', 'staff_key' => 'hq_teller', 'time' => '09:00:00'],
            ['branch_key' => 'hq', 'service_key' => 'premium', 'staff_key' => 'ops_manager', 'time' => '10:20:00'],
            ['branch_key' => 'alg', 'service_key' => 'business', 'staff_key' => 'alg_manager', 'time' => '11:40:00'],
            ['branch_key' => 'cst', 'service_key' => 'digital', 'staff_key' => 'cst_advisor', 'time' => '13:10:00'],
            ['branch_key' => 'orn', 'service_key' => 'card', 'staff_key' => null, 'time' => '15:30:00'],
        ];

        $sequence = 0;

        for ($dayOffset = -30; $dayOffset <= (12 + (($scale - 1) * 6)); $dayOffset++) {
            foreach ($scenarios as $scenarioIndex => $scenario) {
                for ($replica = 0; $replica < $scale; $replica++) {
                    $sequence++;
                    $date = today()->copy()->addDays($dayOffset);
                    $customer = $customers[$customerKeys[$sequence % count($customerKeys)]];
                    $time = $this->shiftTime($scenario['time'], $replica * 25);

                    $status = match (true) {
                        $dayOffset > 0 && $sequence % 5 === 0 => AppointmentStatus::Pending,
                        $dayOffset > 0 => AppointmentStatus::Confirmed,
                        $dayOffset === 0 && $scenarioIndex === 0 => AppointmentStatus::Active,
                        $dayOffset === 0 && $sequence % 4 === 0 => AppointmentStatus::Pending,
                        $dayOffset < 0 && $sequence % 11 === 0 => AppointmentStatus::Cancelled,
                        $dayOffset < 0 && $sequence % 7 === 0 => AppointmentStatus::NoShow,
                        default => AppointmentStatus::Confirmed,
                    };

                    $staffId = in_array($status, [AppointmentStatus::Pending, AppointmentStatus::Cancelled], true)
                        ? null
                        : ($scenario['staff_key'] ? $staff[$scenario['staff_key']]['member']->getKey() : null);

                    $appointment = Appointment::query()->updateOrCreate(
                        [
                            'customer_id' => $customer->getKey(),
                            'branch_id' => $branches[$scenario['branch_key']]->getKey(),
                            'service_id' => $services[$scenario['service_key']]->getKey(),
                            'appointment_date' => $date->toDateString(),
                            'appointment_time' => $time,
                        ],
                        [
                            'staff_id' => $staffId,
                            'appointment_status' => $status,
                        ]
                    );

                    $createdAt = $dayOffset >= 0
                        ? now()->subDays(max(1, 14 - $dayOffset))->setTime(8 + ($sequence % 7), 5)
                        : $date->copy()->subDays(2)->setTime(8 + ($sequence % 8), 15);

                    $this->setTimestamps(
                        $appointment,
                        $createdAt,
                        $dayOffset > 0
                            ? $createdAt->copy()->addHours(3)
                            : min(now(), $createdAt->copy()->addHours(6))
                    );
                }
            }
        }
    }

    protected function seedHighVolumeWalkInsAndQueue(
        array $branches,
        array $services,
        array $staff,
        array $customers,
        array $kiosks,
    ): void {
        $scale = $this->loadScale();
        $customerKeys = array_keys($customers);
        $scenarios = [
            ['branch_key' => 'hq', 'service_key' => 'inquiry', 'served_by_key' => 'hq_teller', 'kiosk_key' => 'hq_front', 'ticket_base' => 5000],
            ['branch_key' => 'hq', 'service_key' => 'cash', 'served_by_key' => 'ops_manager', 'kiosk_key' => 'hq_priority', 'ticket_base' => 6000],
            ['branch_key' => 'alg', 'service_key' => 'business', 'served_by_key' => 'alg_manager', 'kiosk_key' => 'alg_business', 'ticket_base' => 7000],
            ['branch_key' => 'cst', 'service_key' => 'digital', 'served_by_key' => 'cst_advisor', 'kiosk_key' => 'cst_quick', 'ticket_base' => 8000],
            ['branch_key' => 'orn', 'service_key' => 'card', 'served_by_key' => 'oran_support', 'kiosk_key' => 'orn_side', 'ticket_base' => 9000],
        ];

        $sequence = 0;

        for ($daysBack = 0; $daysBack <= (11 + (($scale - 1) * 4)); $daysBack++) {
            $sessionDate = today()->copy()->subDays($daysBack);

            foreach ($scenarios as $scenarioIndex => $scenario) {
                $sessionStatus = $daysBack === 0
                    ? match ($scenarioIndex) {
                        2 => QueueSessionStatus::Paused,
                        4 => QueueSessionStatus::ClosingSoon,
                        default => QueueSessionStatus::Live,
                    }
                    : QueueSessionStatus::Live;

                $session = DailyQueueSession::query()->updateOrCreate(
                    [
                        'branch_id' => $branches[$scenario['branch_key']]->getKey(),
                        'service_id' => $services[$scenario['service_key']]->getKey(),
                        'session_date' => $sessionDate->toDateString(),
                    ],
                    [
                        'session_start_time' => '08:00:00',
                        'session_end_time' => '18:00:00',
                        'session_status' => $sessionStatus,
                    ]
                );
                $positionOffset = (int) QueueEntry::query()
                    ->where('queue_session_id', $session->getKey())
                    ->max('queue_position');

                for ($position = 1; $position <= (6 * $scale); $position++) {
                    $sequence++;
                    $queuePosition = $positionOffset + $position;
                    $customer = $customers[$customerKeys[$sequence % count($customerKeys)]];
                    $ticketNumber = $scenario['ticket_base'] + ($daysBack * 10) + $position;
                    $createdAt = $sessionDate->copy()->setTime(8 + $position, 5 + (($sequence * 7) % 40));

                    if ($daysBack === 0) {
                        $queueStatus = match ($position) {
                            1 => QueueEntryStatus::Serving,
                            2 => QueueEntryStatus::Next,
                            default => QueueEntryStatus::Waiting,
                        };
                    } else {
                        $queueStatus = match (true) {
                            $position === 6 && $daysBack % 4 === 0 => QueueEntryStatus::Cancelled,
                            $position === 5 && $daysBack % 3 === 0 => QueueEntryStatus::Cancelled,
                            default => QueueEntryStatus::Completed,
                        };
                    }

                    $ticketStatus = match ($queueStatus) {
                        QueueEntryStatus::Serving => TicketStatus::Serving,
                        QueueEntryStatus::Next => TicketStatus::CheckedIn,
                        QueueEntryStatus::Waiting => TicketStatus::Queued,
                        QueueEntryStatus::Completed => TicketStatus::Completed,
                        QueueEntryStatus::Cancelled => TicketStatus::Escalated,
                    };

                    $checkedInAt = in_array($queueStatus, [QueueEntryStatus::Serving, QueueEntryStatus::Next, QueueEntryStatus::Completed], true)
                        ? $createdAt->copy()->addMinutes(4)
                        : null;
                    $serviceStartedAt = in_array($queueStatus, [QueueEntryStatus::Serving, QueueEntryStatus::Completed], true)
                        ? $createdAt->copy()->addMinutes(11)
                        : null;
                    $servedByStaffId = in_array($queueStatus, [QueueEntryStatus::Serving, QueueEntryStatus::Completed], true)
                        ? $staff[$scenario['served_by_key']]['member']->getKey()
                        : null;
                    $tokenStatus = match ($queueStatus) {
                        QueueEntryStatus::Waiting => TokenStatus::Active,
                        QueueEntryStatus::Cancelled => TokenStatus::Expired,
                        default => TokenStatus::Consumed,
                    };
                    $checkInResult = match ($queueStatus) {
                        QueueEntryStatus::Serving, QueueEntryStatus::Next, QueueEntryStatus::Completed => CheckInResult::Success,
                        QueueEntryStatus::Cancelled => CheckInResult::ManualAssist,
                        default => null,
                    };

                    $ticket = WalkInTicket::query()->updateOrCreate(
                        [
                            'branch_id' => $branches[$scenario['branch_key']]->getKey(),
                            'ticket_number' => $ticketNumber,
                        ],
                        [
                            'customer_id' => $customer->getKey(),
                            'service_id' => $services[$scenario['service_key']]->getKey(),
                            'queue_session_id' => $session->getKey(),
                            'appointment_id' => null,
                            'ticket_source' => match ($position % 4) {
                                0 => TicketSource::StaffAssisted,
                                1 => TicketSource::Reception,
                                2 => TicketSource::Kiosk,
                                default => TicketSource::QrScan,
                            },
                            'ticket_status' => $ticketStatus,
                            'notes' => $queueStatus === QueueEntryStatus::Cancelled
                                ? 'Escalated during load test scenario to simulate interrupted service flow.'
                                : null,
                        ]
                    );

                    $this->setTimestamps(
                        $ticket,
                        $createdAt,
                        $serviceStartedAt ?? $checkedInAt ?? $createdAt->copy()->addMinutes(18)
                    );

                    $entry = QueueEntry::query()->updateOrCreate(
                        ['ticket_id' => $ticket->getKey()],
                        [
                            'queue_session_id' => $session->getKey(),
                            'customer_id' => $customer->getKey(),
                            'queue_position' => $queuePosition,
                            'queue_status' => $queueStatus,
                            'checked_in_at' => $checkedInAt,
                            'service_started_at' => $serviceStartedAt,
                            'served_by_staff_id' => $servedByStaffId,
                            'appointment_id' => null,
                        ]
                    );

                    $this->setTimestamps(
                        $entry,
                        $createdAt,
                        $serviceStartedAt ?? $checkedInAt ?? $createdAt->copy()->addMinutes(20)
                    );

                    $tokenValue = sprintf(
                        'LOAD-%s-%s-%02d-%02d',
                        strtoupper($scenario['branch_key']),
                        $sessionDate->format('Ymd'),
                        $scenarioIndex + 1,
                        $position
                    );

                    $token = QrCodeToken::query()->updateOrCreate(
                        ['token_value' => $tokenValue],
                        [
                            'ticket_id' => $ticket->getKey(),
                            'appointment_id' => null,
                            'expiration_date_time' => $createdAt->copy()->addHours(8),
                            'used_date_time' => $checkedInAt,
                            'token_status' => $tokenStatus,
                        ]
                    );

                    $this->setTimestamps($token, $createdAt, $checkedInAt ?? $createdAt);

                    if ($checkInResult !== null) {
                        $record = CheckInRecord::query()->updateOrCreate(
                            ['qr_token_id' => $token->getKey()],
                            [
                                'kiosk_id' => $kiosks[$scenario['kiosk_key']]->getKey(),
                                'customer_id' => $customer->getKey(),
                                'check_in_date_time' => $checkedInAt ?? $createdAt,
                                'check_in_result' => $checkInResult,
                            ]
                        );

                        $this->setTimestamps($record, $checkedInAt ?? $createdAt, $checkedInAt ?? $createdAt);
                    }
                }
            }
        }
    }

    protected function seedHighVolumeNotifications(array $staff): void
    {
        $scale = $this->loadScale();
        $notificationTypes = [
            'queue_alert',
            'service_update',
            'branch_status',
            'daily_summary',
            'staffing',
            'security',
        ];
        $channels = [
            NotificationChannel::InApp,
            NotificationChannel::Push,
            NotificationChannel::Email,
            NotificationChannel::Sms,
        ];
        $staffKeys = ['admin', 'ops_manager', 'alg_manager', 'cst_manager'];

        for ($index = 1; $index <= (48 * $scale); $index++) {
            $userKey = $staffKeys[$index % count($staffKeys)];
            $type = $notificationTypes[$index % count($notificationTypes)];
            $occurredAt = today()->copy()
                ->subDays((int) floor(($index - 1) / 8))
                ->setTime(8 + ($index % 9), ($index * 7) % 60);
            $deliveryStatus = match (true) {
                $index % 9 === 0 => NotificationDeliveryStatus::Failed,
                $index % 4 === 0 => NotificationDeliveryStatus::Pending,
                default => NotificationDeliveryStatus::Sent,
            };
            $readAt = $index % 3 === 0 ? null : $occurredAt->copy()->addMinutes(20);
            $tone = match ($type) {
                'queue_alert', 'branch_status' => 'warning',
                'security' => 'critical',
                'daily_summary' => 'success',
                default => 'info',
            };

            $notification = Notification::query()->updateOrCreate(
                [
                    'user_id' => $staff[$userKey]['user']->getKey(),
                    'notification_type' => $type,
                    'occurred_at' => $occurredAt,
                ],
                [
                    'title' => sprintf('Load test %s #%02d', str_replace('_', ' ', $type), $index),
                    'description' => 'Synthetic notification generated to validate list rendering, unread counts, filtering, and pagination under heavier traffic.',
                    'tone' => $tone,
                    'action_path' => '/dashboard',
                    'notification_channel' => $channels[$index % count($channels)],
                    'delivery_status' => $deliveryStatus,
                    'message_content' => sprintf('Synthetic %s notification generated for load scenario #%02d.', $type, $index),
                    'read_at' => $readAt,
                ]
            );

            $this->setTimestamps($notification, $occurredAt, $readAt ?? $occurredAt);
        }
    }

    protected function loadScale(): int
    {
        return max(1, (int) env('SEED_LOAD_MULTIPLIER', app()->environment('production') ? 3 : 1));
    }

    protected function shiftTime(string $time, int $minutes): string
    {
        return Carbon::createFromFormat('H:i:s', $time)
            ->addMinutes($minutes)
            ->format('H:i:s');
    }

    protected function syncRole(User $user, UserRoleName $role): void
    {
        UserRole::query()->where('user_id', $user->getKey())->delete();
        UserRole::query()->create([
            'user_id' => $user->getKey(),
            'role_name' => $role,
        ]);
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
