<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->index(['appointment_date', 'appointment_time'], 'appointments_date_time_index');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'occurred_at'], 'notifications_user_occurred_at_index');
        });

        Schema::table('queue_entries', function (Blueprint $table) {
            $table->index(['queue_status', 'updated_at'], 'queue_entries_status_updated_at_index');
            $table->index('checked_in_at', 'queue_entries_checked_in_at_index');
        });

        Schema::table('walk_in_tickets', function (Blueprint $table) {
            $table->index('created_at', 'walk_in_tickets_created_at_index');
            $table->index(['branch_id', 'created_at'], 'walk_in_tickets_branch_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('walk_in_tickets', function (Blueprint $table) {
            $table->dropIndex('walk_in_tickets_branch_created_at_index');
            $table->dropIndex('walk_in_tickets_created_at_index');
        });

        Schema::table('queue_entries', function (Blueprint $table) {
            $table->dropIndex('queue_entries_checked_in_at_index');
            $table->dropIndex('queue_entries_status_updated_at_index');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_occurred_at_index');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_date_time_index');
        });
    }
};
