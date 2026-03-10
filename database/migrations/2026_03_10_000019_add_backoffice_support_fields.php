<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('branches', 'latitude')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->decimal('latitude', 10, 7)->nullable()->after('branch_address');
            });
        }

        if (! Schema::hasColumn('branches', 'longitude')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            });
        }

        if (! Schema::hasColumn('branches', 'pin_top')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->unsignedTinyInteger('pin_top')->nullable()->after('branch_status');
            });
        }

        if (! Schema::hasColumn('branches', 'pin_left')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->unsignedTinyInteger('pin_left')->nullable()->after('pin_top');
            });
        }

        if (! Schema::hasColumn('staff_members', 'display_staff_code')) {
            Schema::table('staff_members', function (Blueprint $table) {
                $table->string('display_staff_code')->nullable()->unique()->after('full_name');
            });
        }

        if (! Schema::hasColumn('staff_members', 'is_online')) {
            Schema::table('staff_members', function (Blueprint $table) {
                $table->boolean('is_online')->default(false)->after('avatar_url');
            });
        }

        if (! Schema::hasColumn('staff_members', 'last_active_at')) {
            Schema::table('staff_members', function (Blueprint $table) {
                $table->timestamp('last_active_at')->nullable()->after('is_online');
            });
        }

        if (! Schema::hasColumn('appointments', 'appointment_date')) {
            $afterColumn = Schema::hasColumn('appointments', 'start_date_time')
                ? 'start_date_time'
                : 'service_id';

            Schema::table('appointments', function (Blueprint $table) use ($afterColumn) {
                $table->date('appointment_date')->nullable()->after($afterColumn);
            });
        }

        if (! Schema::hasColumn('appointments', 'appointment_time')) {
            $afterColumn = Schema::hasColumn('appointments', 'appointment_date')
                ? 'appointment_date'
                : (Schema::hasColumn('appointments', 'start_date_time') ? 'start_date_time' : 'service_id');

            Schema::table('appointments', function (Blueprint $table) use ($afterColumn) {
                $table->time('appointment_time')->nullable()->after($afterColumn);
            });
        }

        if (Schema::hasColumn('appointments', 'start_date_time')) {
            if (Schema::hasColumn('appointments', 'appointment_date')) {
                DB::table('appointments')
                    ->whereNull('appointment_date')
                    ->whereNotNull('start_date_time')
                    ->update([
                        'appointment_date' => DB::raw('DATE(start_date_time)'),
                    ]);
            }

            if (Schema::hasColumn('appointments', 'appointment_time')) {
                DB::table('appointments')
                    ->whereNull('appointment_time')
                    ->whereNotNull('start_date_time')
                    ->update([
                        'appointment_time' => DB::raw('TIME(start_date_time)'),
                    ]);
            }
        }

        if (! Schema::hasColumn('notifications', 'title')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->string('title')->nullable()->after('notification_type');
            });
        }

        if (! Schema::hasColumn('notifications', 'description')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->text('description')->nullable()->after('title');
            });
        }

        if (! Schema::hasColumn('notifications', 'tone')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->enum('tone', ['critical', 'warning', 'info', 'success'])->default('info')->after('description');
            });
        }

        if (! Schema::hasColumn('notifications', 'action_path')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->string('action_path')->nullable()->after('tone');
            });
        }

        if (! Schema::hasColumn('notifications', 'occurred_at')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->timestamp('occurred_at')->nullable()->after('action_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('notifications', 'occurred_at')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropColumn('occurred_at');
            });
        }

        if (Schema::hasColumn('notifications', 'action_path')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropColumn('action_path');
            });
        }

        if (Schema::hasColumn('notifications', 'tone')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropColumn('tone');
            });
        }

        if (Schema::hasColumn('notifications', 'description')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }

        if (Schema::hasColumn('notifications', 'title')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropColumn('title');
            });
        }

        if (Schema::hasColumn('appointments', 'appointment_time')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->dropColumn('appointment_time');
            });
        }

        if (Schema::hasColumn('appointments', 'appointment_date')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->dropColumn('appointment_date');
            });
        }

        if (Schema::hasColumn('staff_members', 'display_staff_code')) {
            Schema::table('staff_members', function (Blueprint $table) {
                $table->dropUnique('staff_members_display_staff_code_unique');
                $table->dropColumn('display_staff_code');
            });
        }

        if (Schema::hasColumn('staff_members', 'last_active_at')) {
            Schema::table('staff_members', function (Blueprint $table) {
                $table->dropColumn('last_active_at');
            });
        }

        if (Schema::hasColumn('staff_members', 'is_online')) {
            Schema::table('staff_members', function (Blueprint $table) {
                $table->dropColumn('is_online');
            });
        }

        if (Schema::hasColumn('branches', 'pin_left')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('pin_left');
            });
        }

        if (Schema::hasColumn('branches', 'pin_top')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('pin_top');
            });
        }

        if (Schema::hasColumn('branches', 'longitude')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('longitude');
            });
        }

        if (Schema::hasColumn('branches', 'latitude')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('latitude');
            });
        }
    }
};
