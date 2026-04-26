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
        Schema::create('counter_service', function (Blueprint $table) {
            $table->foreignUuid('counter_id')
                ->constrained('counters')
                ->cascadeOnDelete();
            $table->foreignUuid('service_id')
                ->constrained('services')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['counter_id', 'service_id']);
            $table->index('service_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('counter_service');
    }
};
