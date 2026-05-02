<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_members', function (Blueprint $table) {
            $table->foreignUuid('counter_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('counters')
                ->nullOnDelete();

            $table->index('counter_id');
        });

        // Backfill counter assignment for existing staff by matching branch + service.
        DB::statement("
            UPDATE staff_members sm
            LEFT JOIN (
                SELECT cs2.service_id, c2.branch_id, MIN(c2.id) AS first_counter_id
                FROM counter_service cs2
                JOIN counters c2 ON c2.id = cs2.counter_id
                GROUP BY cs2.service_id, c2.branch_id
            ) map ON map.service_id = sm.service_id AND map.branch_id = sm.branch_id
            SET sm.counter_id = map.first_counter_id
            WHERE sm.counter_id IS NULL
              AND sm.service_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('staff_members', function (Blueprint $table) {
            $table->dropForeign(['counter_id']);
            $table->dropColumn('counter_id');
        });
    }
};
