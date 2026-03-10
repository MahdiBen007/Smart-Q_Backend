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
        Schema::create('check_in_records', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Operational history: preserve check-in records and block token deletion while referenced.
            $table->foreignUuid('qr_token_id')
                ->constrained('qr_code_tokens')
                ->restrictOnDelete();

            // Operational history: preserve check-in records and block kiosk deletion while referenced.
            $table->foreignUuid('kiosk_id')
                ->constrained('kiosk_devices')
                ->restrictOnDelete();

            // Operational history: preserve check-in records and block customer deletion while referenced.
            $table->foreignUuid('customer_id')
                ->constrained('customers')
                ->restrictOnDelete();

            $table->dateTime('check_in_date_time');
            $table->enum('check_in_result', ['success', 'pending', 'manual_assist']);
            $table->timestamps();

            $table->index('check_in_date_time');
            $table->index(['customer_id', 'check_in_date_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_in_records');
    }
};
