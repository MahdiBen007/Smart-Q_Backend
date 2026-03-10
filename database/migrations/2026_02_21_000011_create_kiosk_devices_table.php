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
        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Dependency data: kiosk devices are branch-scoped and should be removed with branch deletion.
            $table->foreignUuid('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->string('device_identifier')->unique();
            $table->string('device_location_description');
            $table->enum('device_status', ['online', 'busy', 'maintenance'])->default('online');
            $table->timestamps();

            $table->index('branch_id');
            $table->index('device_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kiosk_devices');
    }
};
