<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('daily_queue_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Dependency data: queue sessions depend on branch lifecycle.
            $table->foreignUuid('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            // Dependency data: queue sessions depend on service lifecycle.
            $table->foreignUuid('service_id')
                ->constrained('services')
                ->cascadeOnDelete();

            $table->date('session_date');
            $table->time('session_start_time');
            $table->time('session_end_time');
            $table->enum('session_status', ['live', 'closing_soon', 'paused'])->default('live');
            $table->timestamps();

            $table->unique(['branch_id', 'service_id', 'session_date']);
            $table->index(['branch_id', 'session_date']);
            $table->index('session_status');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE daily_queue_sessions ADD CONSTRAINT chk_daily_queue_sessions_end_after_start CHECK (session_end_time > session_start_time)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_queue_sessions');
    }
};
