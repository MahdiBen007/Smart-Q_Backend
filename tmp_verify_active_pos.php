<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = app(App\Http\Controllers\Api\Dashboard\Appointments\AppointmentController::class);
$method = new ReflectionMethod($controller, 'activeQueuePosition');
$method->setAccessible(true);

$entry = App\Models\QueueEntry::query()
  ->whereNotIn('queue_status',['completed','cancelled'])
  ->orderByDesc('updated_at')
  ->first();

if (!$entry) { echo "NO_ACTIVE_ENTRY\n"; exit(0);} 
$pos = $method->invoke($controller, $entry);
echo "ENTRY={$entry->id} RAW_POS={$entry->queue_position} ACTIVE_POS={$pos}\n";
