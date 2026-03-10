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
        Schema::table('branches', function (Blueprint $table) {
            $table->string('branch_code')->nullable()->unique();
            $table->enum('branch_status', ['active', 'peak', 'maintenance'])->default('active');

            $table->index('branch_status');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('service_code')->nullable()->unique();
            $table->string('service_subtitle')->nullable();
            $table->text('service_description')->nullable();
            $table->string('service_icon')->nullable();
        });

        Schema::table('staff_members', function (Blueprint $table) {
            $table->string('avatar_url')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_members', function (Blueprint $table) {
            $table->dropColumn([
                'avatar_url',
            ]);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique('services_service_code_unique');
            $table->dropColumn([
                'service_code',
                'service_subtitle',
                'service_description',
                'service_icon',
            ]);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropIndex(['branch_status']);
            $table->dropUnique('branches_branch_code_unique');
            $table->dropColumn([
                'branch_code',
                'branch_status',
            ]);
        });
    }
};
