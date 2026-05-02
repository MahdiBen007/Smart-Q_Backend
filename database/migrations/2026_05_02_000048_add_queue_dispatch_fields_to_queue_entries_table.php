<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_entries', function (Blueprint $table) {
            $table->enum('queue_type', ['regular', 'priority'])
                ->default('regular')
                ->after('queue_status');
            $table->dateTime('calling_started_at')->nullable()->after('checked_in_at');
            $table->unsignedSmallInteger('wait_timeout_seconds')->default(30)->after('calling_started_at');
            $table->foreignUuid('reserved_by_counter_id')
                ->nullable()
                ->after('served_by_staff_id')
                ->constrained('counters')
                ->nullOnDelete();
            $table->dateTime('reserved_until')->nullable()->after('reserved_by_counter_id');
            $table->string('cancel_reason', 50)->nullable()->after('reserved_until');

            $table->index(['queue_session_id', 'queue_type', 'queue_status', 'queue_position'], 'queue_entries_dispatch_idx');
        });

        DB::table('queue_entries')
            ->whereNull('wait_timeout_seconds')
            ->update(['wait_timeout_seconds' => 30]);
    }

    public function down(): void
    {
        Schema::table('queue_entries', function (Blueprint $table) {
            $table->dropIndex('queue_entries_dispatch_idx');
            $table->dropForeign(['reserved_by_counter_id']);
            $table->dropColumn([
                'queue_type',
                'calling_started_at',
                'wait_timeout_seconds',
                'reserved_by_counter_id',
                'reserved_until',
                'cancel_reason',
            ]);
        });
    }
};
