<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Enums\EmploymentStatus;
use App\Enums\UserRoleName;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class CnasOnlySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        DB::transaction(function (): void {
            // Keep only CNAS scenario: wipe operational data then companies/services.
            $tablesToWipe = [
                'check_in_records',
                'qr_code_tokens',
                'queue_entries',
                'walk_in_tickets',
                'appointments',
                'daily_queue_sessions',
                'kiosk_devices',
                'notifications',
                'branch_service',
                'user_roles',
                'staff_members',
                'jwt_tokens',
                'password_reset_tokens',
                'user_preferences',
                'device_tokens',
                'customers',
                'users',
                'branches',
                'services',
                'companies',
            ];

            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($tablesToWipe as $tableName) {
                if (Schema::hasTable($tableName)) {
                    DB::table($tableName)->delete();
                }
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            $company = Company::query()->create([
                'company_name' => 'CNAS',
                'company_status' => CompanyStatus::Active,
            ]);

            $branches = collect([
                [
                    'key' => 'algiers_center',
                    'branch_name' => 'CNAS Alger Centre',
                    'branch_address' => 'Rue Didouche Mourad, Alger',
                    'branch_code' => 'CNAS-ALG-01',
                    'branch_status' => 'active',
                    'latitude' => 36.7538,
                    'longitude' => 3.0588,
                    'pin_top' => 38,
                    'pin_left' => 50,
                ],
                [
                    'key' => 'bab_ezzouar',
                    'branch_name' => 'CNAS Bab Ezzouar',
                    'branch_address' => 'Bab Ezzouar, Alger',
                    'branch_code' => 'CNAS-ALG-02',
                    'branch_status' => 'active',
                    'latitude' => 36.7262,
                    'longitude' => 3.1822,
                    'pin_top' => 45,
                    'pin_left' => 58,
                ],
                [
                    'key' => 'oran',
                    'branch_name' => 'CNAS Oran',
                    'branch_address' => 'Boulevard de l\'ALN, Oran',
                    'branch_code' => 'CNAS-ORN-01',
                    'branch_status' => 'active',
                    'latitude' => 35.6981,
                    'longitude' => -0.6348,
                    'pin_top' => 64,
                    'pin_left' => 26,
                ],
                [
                    'key' => 'constantine',
                    'branch_name' => 'CNAS Constantine',
                    'branch_address' => 'Rue Larbi Ben M\'hidi, Constantine',
                    'branch_code' => 'CNAS-CST-01',
                    'branch_status' => 'active',
                    'latitude' => 36.3650,
                    'longitude' => 6.6147,
                    'pin_top' => 43,
                    'pin_left' => 68,
                ],
            ])->mapWithKeys(function (array $definition) use ($company): array {
                $branch = Branch::query()->create([
                    'company_id' => $company->getKey(),
                    'branch_name' => $definition['branch_name'],
                    'branch_address' => $definition['branch_address'],
                    'branch_code' => $definition['branch_code'],
                    'branch_status' => $definition['branch_status'],
                    'latitude' => $definition['latitude'],
                    'longitude' => $definition['longitude'],
                    'pin_top' => $definition['pin_top'],
                    'pin_left' => $definition['pin_left'],
                ]);

                return [$definition['key'] => $branch];
            });

            $services = collect([
                [
                    'key' => 'carte_chifa',
                    'service_name' => 'Carte Chifa',
                    'service_code' => 'CNAS-SRV-001',
                    'service_subtitle' => 'Creation et renouvellement',
                    'service_description' => 'Depot et suivi des dossiers de creation, renouvellement et remplacement de la carte Chifa.',
                    'service_icon' => 'card',
                    'average_service_duration_minutes' => 15,
                    'branch_keys' => ['algiers_center', 'bab_ezzouar', 'oran', 'constantine'],
                ],
                [
                    'key' => 'depot_dossier',
                    'service_name' => 'Depot de Dossier',
                    'service_code' => 'CNAS-SRV-002',
                    'service_subtitle' => 'Affiliation et mise a jour',
                    'service_description' => 'Reception des dossiers d\'affiliation, mise a jour de situation et ajout des ayants droit.',
                    'service_icon' => 'folder',
                    'average_service_duration_minutes' => 12,
                    'branch_keys' => ['algiers_center', 'bab_ezzouar', 'oran', 'constantine'],
                ],
                [
                    'key' => 'remboursement',
                    'service_name' => 'Remboursement',
                    'service_code' => 'CNAS-SRV-003',
                    'service_subtitle' => 'Suivi des remboursements',
                    'service_description' => 'Traitement des demandes de remboursement des soins, analyses et medicaments.',
                    'service_icon' => 'wallet',
                    'average_service_duration_minutes' => 18,
                    'branch_keys' => ['algiers_center', 'bab_ezzouar', 'oran', 'constantine'],
                ],
                [
                    'key' => 'controle_medical',
                    'service_name' => 'Controle Medical',
                    'service_code' => 'CNAS-SRV-004',
                    'service_subtitle' => 'Arrets de travail',
                    'service_description' => 'Validation et suivi des dossiers lies aux arrets maladie et controles medicaux.',
                    'service_icon' => 'stethoscope',
                    'average_service_duration_minutes' => 20,
                    'branch_keys' => ['algiers_center', 'oran', 'constantine'],
                ],
            ])->mapWithKeys(function (array $definition) use ($branches): array {
                $primaryBranch = $branches[$definition['branch_keys'][0]];
                $service = Service::query()->create([
                    'branch_id' => $primaryBranch->getKey(),
                    'service_name' => $definition['service_name'],
                    'service_code' => $definition['service_code'],
                    'service_subtitle' => $definition['service_subtitle'],
                    'service_description' => $definition['service_description'],
                    'service_icon' => $definition['service_icon'],
                    'average_service_duration_minutes' => $definition['average_service_duration_minutes'],
                    'is_active' => true,
                ]);

                $service->branches()->sync(
                    collect($definition['branch_keys'])
                        ->map(fn (string $branchKey) => $branches[$branchKey]->getKey())
                        ->all()
                );

                return [$definition['key'] => $service];
            });

            $this->seedDashboardUser(
                email: 'mahdi@cnas.dz',
                phoneNumber: '+213550000001',
                password: '12345678',
                role: UserRoleName::Admin,
                companyId: $company->getKey(),
                branchId: $branches['algiers_center']->getKey(),
                serviceId: null,
                fullName: 'Mahdi Admin',
                displayStaffCode: 'CNAS-ADM-01',
                isOnline: true,
            );

            $this->seedDashboardUser(
                email: 'admin@cnas.dz',
                phoneNumber: '+213550000005',
                password: '12345678',
                role: UserRoleName::Admin,
                companyId: $company->getKey(),
                branchId: $branches['algiers_center']->getKey(),
                serviceId: null,
                fullName: 'Admin CNAS',
                displayStaffCode: 'CNAS-ADM-02',
                isOnline: true,
            );

            $this->seedBranchAdmins(
                companyId: $company->getKey(),
                branches: $branches,
            );

            $workers = [
                [
                    'email' => 'worker1@cnas.dz',
                    'phone' => '+213550000101',
                    'name' => 'Worker Chifa',
                    'code' => 'CNAS-WRK-01',
                    'branch_key' => 'algiers_center',
                    'service_key' => 'carte_chifa',
                ],
                [
                    'email' => 'worker2@cnas.dz',
                    'phone' => '+213550000102',
                    'name' => 'Worker Dossier',
                    'code' => 'CNAS-WRK-02',
                    'branch_key' => 'bab_ezzouar',
                    'service_key' => 'depot_dossier',
                ],
                [
                    'email' => 'worker3@cnas.dz',
                    'phone' => '+213550000103',
                    'name' => 'Worker Remboursement',
                    'code' => 'CNAS-WRK-03',
                    'branch_key' => 'oran',
                    'service_key' => 'remboursement',
                ],
                [
                    'email' => 'worker4@cnas.dz',
                    'phone' => '+213550000104',
                    'name' => 'Worker Controle',
                    'code' => 'CNAS-WRK-04',
                    'branch_key' => 'constantine',
                    'service_key' => 'controle_medical',
                ],
            ];

            foreach ($workers as $worker) {
                $this->seedDashboardUser(
                    email: $worker['email'],
                    phoneNumber: $worker['phone'],
                    password: '12345678',
                    role: UserRoleName::Staff,
                    companyId: $company->getKey(),
                    branchId: $branches[$worker['branch_key']]->getKey(),
                    serviceId: $services[$worker['service_key']]->getKey(),
                    fullName: $worker['name'],
                    displayStaffCode: $worker['code'],
                    isOnline: true,
                );
            }
        });
    }

    protected function seedBranchAdmins(string $companyId, Collection $branches): void
    {
        $branchAdminPassword = '12345678';
        $phoneNumberOffset = 200;

        foreach ($branches->values() as $index => $branch) {
            $branchCode = (string) $branch->branch_code;
            $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $branchCode));
            $slug = trim($slug, '-');

            $email = "branchadmin.{$slug}@cnas.dz";
            $phoneNumber = sprintf('+213550000%03d', $phoneNumberOffset + $index);

            $shortBranchCode = str_starts_with($branchCode, 'CNAS-')
                ? substr($branchCode, strlen('CNAS-'))
                : $branchCode;

            $this->seedDashboardUser(
                email: $email,
                phoneNumber: $phoneNumber,
                password: $branchAdminPassword,
                role: UserRoleName::Manager,
                companyId: $companyId,
                branchId: $branch->getKey(),
                serviceId: null,
                fullName: 'Branch Admin',
                displayStaffCode: "CNAS-BADM-{$shortBranchCode}",
                isOnline: false,
            );
        }
    }

    protected function seedDashboardUser(
        string $email,
        string $phoneNumber,
        string $password,
        UserRoleName $role,
        string $companyId,
        string $branchId,
        ?string $serviceId,
        string $fullName,
        string $displayStaffCode,
        bool $isOnline,
    ): void {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'phone_number' => $phoneNumber,
                'password_hash' => Hash::make($password),
                'is_active' => true,
            ]
        );

        UserRole::query()->where('user_id', $user->getKey())->delete();
        UserRole::query()->create([
            'user_id' => $user->getKey(),
            'role_name' => $role,
        ]);

        StaffMember::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'service_id' => $serviceId,
                'full_name' => $fullName,
                'display_staff_code' => $displayStaffCode,
                'employment_status' => EmploymentStatus::Active,
                'avatar_url' => null,
                'is_online' => $isOnline,
                'last_active_at' => now(),
            ]
        );
    }
}
