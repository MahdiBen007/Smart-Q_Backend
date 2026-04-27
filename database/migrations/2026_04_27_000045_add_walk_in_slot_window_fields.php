<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walk_in_tickets', function (Blueprint $table): void {
            if (! Schema::hasColumn('walk_in_tickets', 'slot_start_time')) {
                $table->time('slot_start_time')->nullable()->after('appointment_id');
            }

            if (! Schema::hasColumn('walk_in_tickets', 'slot_end_time')) {
                $table->time('slot_end_time')->nullable()->after('slot_start_time');
            }

            if (! Schema::hasColumn('walk_in_tickets', 'slot_time_label')) {
                $table->string('slot_time_label', 64)->nullable()->after('slot_end_time');
            }

            if (! Schema::hasColumn('walk_in_tickets', 'slot_session_id')) {
                $table->string('slot_session_id', 64)->nullable()->after('slot_time_label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('walk_in_tickets', function (Blueprint $table): void {
            if (Schema::hasColumn('walk_in_tickets', 'slot_session_id')) {
                $table->dropColumn('slot_session_id');
            }

            if (Schema::hasColumn('walk_in_tickets', 'slot_time_label')) {
                $table->dropColumn('slot_time_label');
            }

            if (Schema::hasColumn('walk_in_tickets', 'slot_end_time')) {
                $table->dropColumn('slot_end_time');
            }

            if (Schema::hasColumn('walk_in_tickets', 'slot_start_time')) {
                $table->dropColumn('slot_start_time');
            }
        });
    }
};
