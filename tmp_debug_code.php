<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$a = App\Models\Appointment::with(['branch.company','service','customer'])
    ->latest('updated_at')
    ->first();

if (! $a) {
    echo "NO_APPOINTMENT\n";
    exit(0);
}

$status = is_object($a->appointment_status) ? $a->appointment_status->value : (string) $a->appointment_status;

echo 'ID='.$a->id.PHP_EOL;
echo 'SERVICE='.($a->service?->service_name ?? '').PHP_EOL;
echo 'BRANCH='.($a->branch?->branch_name ?? '').PHP_EOL;
echo 'DATE='.$a->appointment_date.PHP_EOL;
echo 'STATUS='.$status.PHP_EOL;
echo 'CODE='.App\Support\Dashboard\BookingCodeFormatter::appointmentDisplayCode($a).PHP_EOL;
