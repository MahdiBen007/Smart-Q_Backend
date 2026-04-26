<?php

namespace Database\Seeders;

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

class CnasBranchAdminsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $company = Company::query()
            ->where('company_name', 'CNAS')
            ->first();

        if (! $company) {
            $this->command?->warn('CNAS company not found. Skipping branch admin seeding.');

            return;
        }

        $branches = Branch::query()
            ->where('company_id', $company->getKey())
            ->whereNotNull('branch_code')
            ->orderBy('branch_code')
            ->get();

        $branchAdminPassword = '12345678';
        $phoneNumberOffset = 200;

        foreach ($branches as $index => $branch) {
            $branchCode = (string) $branch->branch_code;
            $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $branchCode));
            $slug = trim($slug, '-');

            $email = "branchadmin.{$slug}@cnas.dz";
            $phoneNumber = sprintf('+213550000%03d', $phoneNumberOffset + $index);

            $shortBranchCode = str_starts_with($branchCode, 'CNAS-')
                ? substr($branchCode, strlen('CNAS-'))
                : $branchCode;

            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'phone_number' => $phoneNumber,
                    'password_hash' => Hash::make($branchAdminPassword),
                    'is_active' => true,
                ]
            );

            UserRole::query()->where('user_id', $user->getKey())->delete();
            UserRole::query()->create([
                'user_id' => $user->getKey(),
                'role_name' => UserRoleName::Manager,
            ]);

            StaffMember::query()->updateOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'company_id' => $company->getKey(),
                    'branch_id' => $branch->getKey(),
                    'service_id' => null,
                    'full_name' => 'Branch Admin',
                    'display_staff_code' => "CNAS-BADM-{$shortBranchCode}",
                    'employment_status' => EmploymentStatus::Active,
                    'avatar_url' => null,
                    'is_online' => false,
                    'last_active_at' => now(),
                ]
            );
        }

        $this->command?->info("Seeded {$branches->count()} CNAS branch admin account(s).");
    }
}
