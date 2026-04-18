<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = App\Models\Appointment::query()
  ->with(['customer','branch.company','service'])
  ->whereHas('customer', fn($q) => $q->where('full_name','like','%Mahdi%'))
  ->orderByDesc('updated_at')
  ->limit(15)
  ->get();

foreach ($rows as $a) {
  $status = is_object($a->appointment_status) ? $a->appointment_status->value : (string) $a->appointment_status;
  $code = App\Support\Dashboard\BookingCodeFormatter::appointmentDisplayCode($a);
  $entry = App\Models\QueueEntry::query()->where('appointment_id',$a->id)->orderByDesc('updated_at')->first();
  $queueStatus = $entry ? (is_object($entry->queue_status) ? $entry->queue_status->value : (string)$entry->queue_status) : '--';
  $checked = $entry && $entry->checked_in_at ? 'YES' : 'NO';
  echo sprintf("%s | %s | %s | %s | %s | %s | %s\n", $a->id, $a->customer?->full_name ?? '--', $code, $status, $queueStatus, $checked, (string)$a->appointment_date);
}
