<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Appointment;
use App\Models\QueueEntry;

$rows = Appointment::query()
  ->with(['customer','service','branch','queueEntries' => fn($q) => $q->orderByDesc('created_at')])
  ->whereHas('customer', fn($q)=>$q->where('full_name','like','%Mahdi%'))
  ->orderByDesc('updated_at')
  ->limit(5)
  ->get();

foreach ($rows as $a) {
  $status = is_object($a->appointment_status) ? $a->appointment_status->value : (string)$a->appointment_status;
  echo "APPT {$a->id} | {$a->customer?->full_name} | status={$status} | service=".($a->service?->service_name??'')."\n";
  foreach ($a->queueEntries as $e) {
    $qs = is_object($e->queue_status) ? $e->queue_status->value : (string)$e->queue_status;
    $checked = $e->checked_in_at ? $e->checked_in_at : '--';
    echo "  QE {$e->id} | status={$qs} | pos={$e->queue_position} | checked={$checked} | created={$e->created_at} | updated={$e->updated_at}\n";
  }
}
