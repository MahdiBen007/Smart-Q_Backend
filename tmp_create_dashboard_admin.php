<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\UserRole;
use App\Models\StaffMember;
use App\Models\Company;
use App\Models\Branch;
use Illuminate\Support\Facades\Hash;

$email = 'dashboard@smartq.com';
$password = '12345678';

$company = Company::query()->first();
if (! $company) {
    $company = Company::query()->create([
        'company_name' => 'SmartQ Operations',
        'company_status' => 'active',
    ]);
}

$branch = Branch::query()->where('company_id', $company->getKey())->first();
if (! $branch) {
    $branch = Branch::query()->create([
        'company_id' => $company->getKey(),
        'branch_code' => 'HQ-001',
        'branch_name' => 'Downtown HQ',
        'branch_address' => 'Algiers',
        'branch_status' => 'active',
    ]);
}

$user = User::query()->updateOrCreate(
    ['email' => $email],
    [
        'phone_number' => '+213500009999',
        'password_hash' => Hash::make($password),
        'is_active' => true,
    ]
);

$hasRole = UserRole::query()
    ->where('user_id', $user->getKey())
    ->where('role_name', 'admin')
    ->exists();
if (! $hasRole) {
    UserRole::query()->create([
        'user_id' => $user->getKey(),
        'role_name' => 'admin',
    ]);
}

$staff = StaffMember::query()->where('user_id', $user->getKey())->first();
if (! $staff) {
    $maxCode = StaffMember::query()->whereNotNull('display_staff_code')->orderByDesc('display_staff_code')->value('display_staff_code');
    $next = 1;
    if (is_string($maxCode) && preg_match('/(\d+)$/', $maxCode, $m)) {
        $next = ((int) $m[1]) + 1;
    }
    $code = 'STF-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);

    StaffMember::query()->create([
        'user_id' => $user->getKey(),
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'service_id' => null,
        'display_staff_code' => $code,
        'full_name' => 'Dashboard Admin',
        'employment_status' => 'active',
        'is_online' => false,
        'avatar_url' => null,
    ]);
}

echo "CREATED_OR_UPDATED\n";
echo "EMAIL=$email\n";
echo "PASSWORD=$password\n";
