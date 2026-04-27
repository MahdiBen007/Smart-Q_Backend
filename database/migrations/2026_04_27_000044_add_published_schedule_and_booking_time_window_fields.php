<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations_schedules', function (Blueprint $table): void {
            if (! Schema::hasColumn('operations_schedules', 'published_schedule')) {
                $table->json('published_schedule')->nullable()->after('schedule');
            }
        });

        if (Schema::hasColumn('operations_schedules', 'published_schedule')) {
            DB::table('operations_schedules')
                ->where('status', 'published')
                ->whereNull('published_schedule')
                ->update([
                    'published_schedule' => DB::raw('schedule'),
                ]);
        }

        Schema::table('appointments', function (Blueprint $table): void {
            if (! Schema::hasColumn('appointments', 'appointment_end_time')) {
                $table->time('appointment_end_time')->nullable()->after('appointment_time');
            }

            if (! Schema::hasColumn('appointments', 'appointment_time_label')) {
                $table->string('appointment_time_label', 64)->nullable()->after('appointment_end_time');
            }

            if (! Schema::hasColumn('appointments', 'appointment_session_id')) {
                $table->string('appointment_session_id', 64)->nullable()->after('appointment_time_label');
            }

            if (! Schema::hasColumn('appointments', 'appointment_channel')) {
                $table->string('appointment_channel', 32)->default('in_person')->after('appointment_session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            if (Schema::hasColumn('appointments', 'appointment_channel')) {
                $table->dropColumn('appointment_channel');
            }

            if (Schema::hasColumn('appointments', 'appointment_session_id')) {
                $table->dropColumn('appointment_session_id');
            }

            if (Schema::hasColumn('appointments', 'appointment_time_label')) {
                $table->dropColumn('appointment_time_label');
            }

            if (Schema::hasColumn('appointments', 'appointment_end_time')) {
                $table->dropColumn('appointment_end_time');
            }
        });

        Schema::table('operations_schedules', function (Blueprint $table): void {
            if (Schema::hasColumn('operations_schedules', 'published_schedule')) {
                $table->dropColumn('published_schedule');
            }
        });
    }
};
