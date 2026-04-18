<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$email = 'Mahdi@smartq.com';
$user = User::query()->where('email', $email)->first();
if (! $user) {
    echo "USER_MISSING\n";
    exit(0);
}
$user->password_hash = Hash::make('12345678');
$user->is_active = true;
$user->save();
echo "RESET_OK\n";
