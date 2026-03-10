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
        Schema::create('walk_in_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Dependency data: customer-owned ticket records are removed with the customer.
            $table->foreignUuid('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            // Operational history: prevent deleting branch while tickets reference it.
            $table->foreignUuid('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            // Operational history: prevent deleting service while tickets reference it.
            $table->foreignUuid('service_id')
                ->constrained('services')
                ->restrictOnDelete();

            $table->unsignedInteger('ticket_number');
            $table->enum('ticket_source', ['reception', 'kiosk', 'qr_scan', 'staff_assisted']);
            $table->enum('ticket_status', ['queued', 'checked_in', 'serving', 'completed', 'escalated'])->default('queued');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'ticket_number']);
            $table->index('ticket_status');
            $table->index(['branch_id', 'ticket_status']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE walk_in_tickets ADD CONSTRAINT chk_walk_in_tickets_number_positive CHECK (ticket_number > 0)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('walk_in_tickets');
    }
};
