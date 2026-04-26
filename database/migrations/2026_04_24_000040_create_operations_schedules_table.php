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
        Schema::create('operations_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('scope', 16);
            $table->string('target_key', 128);
            $table->foreignUuid('branch_id')
                ->nullable()
                ->constrained('branches')
                ->cascadeOnDelete();
            $table->foreignUuid('service_id')
                ->nullable()
                ->constrained('services')
                ->cascadeOnDelete();

            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->json('schedule');

            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignUuid('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'target_key'], 'operations_schedules_company_target_unique');
            $table->index(['company_id', 'scope']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operations_schedules');
    }
};
