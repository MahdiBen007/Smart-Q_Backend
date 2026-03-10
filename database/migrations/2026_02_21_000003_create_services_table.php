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
        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Services can exist independently and be assigned through branch_service.
            $table->foreignUuid('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            $table->string('service_name');
            $table->unsignedInteger('average_service_duration_minutes');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('branch_id');
            $table->index('is_active');
            $table->index(['branch_id', 'is_active']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE services ADD CONSTRAINT chk_services_avg_duration_positive CHECK (average_service_duration_minutes > 0)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
