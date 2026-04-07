<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Enums\EmploymentStatus;
use App\Enums\UserRoleName;
use App\Models\Branch;
use App\Models\Company;
use App\Models\StaffMember;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DashboardAccessSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $company = Company::query()->updateOrCreate(
            ['company_name' => 'SmartQdz'],
            ['company_status' => CompanyStatus::Active]
        );

        $branch = Branch::query()->updateOrCreate(
            ['branch_code' => 'MAIN-001'],
            [
                'company_id' => $company->getKey(),
                'branch_name' => 'Main Branch',
                'branch_address' => 'Main Office',
                'branch_status' => 'active',
            ]
        );

        $this->seedDashboardUser(
            email: 'Mahdi@smartq.com',
            phoneNumber: '0550000001',
            password: '12345678',
            role: UserRoleName::Admin,
            company: $company,
            branch: $branch,
            fullName: 'Mahdi Admin',
            displayStaffCode: 'STF-10001',
            isOnline: true,
        );

        $this->seedDashboardUser(
            email: 'moumen@smartq.com',
            phoneNumber: '0550000002',
            password: '12345678',
            role: UserRoleName::Staff,
            company: $company,
            branch: $branch,
            fullName: 'Moumen Staff',
            displayStaffCode: 'STF-10002',
            isOnline: true,
        );
    }

    protected function seedDashboardUser(
        string $email,
        string $phoneNumber,
        string $password,
        UserRoleName $role,
        Company $company,
        Branch $branch,
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
                'company_id' => $company->getKey(),
                'branch_id' => $branch->getKey(),
                'service_id' => null,
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
