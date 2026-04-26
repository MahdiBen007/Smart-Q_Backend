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
        if (Schema::hasColumn('branches', 'logo_url')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->string('logo_url', 2048)->nullable()->after('branch_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('branches', 'logo_url')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('logo_url');
        });
    }
};
