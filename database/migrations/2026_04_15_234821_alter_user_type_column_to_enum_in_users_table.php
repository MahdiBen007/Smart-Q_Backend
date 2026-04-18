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
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE users MODIFY user_type ENUM('regular','special_needs') NOT NULL DEFAULT 'regular'"
            );
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("DO $$
                BEGIN
                    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'user_type_enum') THEN
                        CREATE TYPE user_type_enum AS ENUM ('regular', 'special_needs');
                    END IF;
                END
            $$;");
            DB::statement(
                "ALTER TABLE users ALTER COLUMN user_type TYPE user_type_enum USING user_type::user_type_enum"
            );
            DB::statement("ALTER TABLE users ALTER COLUMN user_type SET DEFAULT 'regular'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE users MODIFY user_type VARCHAR(40) NOT NULL DEFAULT 'regular'"
            );
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE users ALTER COLUMN user_type TYPE VARCHAR(40)");
            DB::statement("ALTER TABLE users ALTER COLUMN user_type SET DEFAULT 'regular'");
            DB::statement("DROP TYPE IF EXISTS user_type_enum");
        }
    }
};
