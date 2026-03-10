<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_roles') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE user_roles MODIFY role_name ENUM('super_admin','admin','manager','staff','support','customer') NOT NULL"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_roles') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE user_roles MODIFY role_name ENUM('super_admin','admin','manager','staff','customer') NOT NULL"
        );
    }
};
