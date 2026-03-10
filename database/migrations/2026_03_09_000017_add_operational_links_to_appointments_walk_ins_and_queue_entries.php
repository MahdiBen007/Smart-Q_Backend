<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignUuid('staff_id')
                ->nullable()
                ->constrained('staff_members')
                ->nullOnDelete();

            $table->index('staff_id');
        });

        Schema::table('walk_in_tickets', function (Blueprint $table) {
            $table->foreignUuid('queue_session_id')
                ->nullable()
                ->constrained('daily_queue_sessions')
                ->nullOnDelete();
            $table->foreignUuid('appointment_id')
                ->nullable()
                ->constrained('appointments')
                ->nullOnDelete();

            $table->index('queue_session_id');
        });

        Schema::table('queue_entries', function (Blueprint $table) {
            $table->dateTime('service_started_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queue_entries', function (Blueprint $table) {
            $table->dropColumn('service_started_at');
        });

        Schema::table('walk_in_tickets', function (Blueprint $table) {
            $table->dropIndex(['queue_session_id']);
            $table->dropForeign(['queue_session_id']);
            $table->dropForeign(['appointment_id']);
            $table->dropColumn([
                'queue_session_id',
                'appointment_id',
            ]);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['staff_id']);
            $table->dropForeign(['staff_id']);
            $table->dropColumn('staff_id');
        });
    }
};
