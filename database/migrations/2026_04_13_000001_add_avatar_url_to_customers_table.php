<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'avatar_url')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->text('avatar_url')->nullable()->after('email_address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'avatar_url')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('avatar_url');
            });
        }
    }
};
