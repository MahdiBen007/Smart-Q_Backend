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
        Schema::create('staff_members', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Operational link: prevent deleting a user that is still tied to staff history.
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Dependency data: removing company should remove dependent staff rows.
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Dependency data: removing branch should remove dependent staff rows.
            $table->foreignUuid('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->string('full_name');
            $table->enum('employment_status', ['active', 'inactive', 'on_leave'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('user_id');
            $table->index('branch_id');
            $table->index('company_id');
            $table->index('employment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_members');
    }
};
