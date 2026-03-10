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
        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Dependency data: customer-owned appointment records are removed with the customer.
            $table->foreignUuid('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            // Operational history: prevent deleting branch while appointments reference it.
            $table->foreignUuid('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            // Operational history: prevent deleting service while appointments reference it.
            $table->foreignUuid('service_id')
                ->constrained('services')
                ->restrictOnDelete();

            $table->date('appointment_date');
            $table->enum('appointment_status', ['pending', 'confirmed', 'active', 'cancelled', 'no_show'])
                ->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->index('appointment_date');
            $table->index('customer_id');
            $table->index(['branch_id', 'appointment_date']);
            $table->index('appointment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
