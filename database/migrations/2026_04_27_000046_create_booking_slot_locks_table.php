<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_slot_locks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();
            $table->foreignUuid('service_id')
                ->constrained('services')
                ->cascadeOnDelete();
            $table->date('slot_date');
            $table->time('slot_start_time');
            $table->string('booking_channel', 32);
            $table->timestamps();

            $table->unique(
                ['branch_id', 'service_id', 'slot_date', 'slot_start_time', 'booking_channel'],
                'booking_slot_locks_unique_slot'
            );
            $table->index(['branch_id', 'slot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_slot_locks');
    }
};
