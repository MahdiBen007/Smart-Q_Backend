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
        Schema::create('branch_service', function (Blueprint $table) {
            $table->foreignUuid('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();
            $table->foreignUuid('service_id')
                ->constrained('services')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'service_id']);
            $table->index('service_id');
        });

        $timestamp = now();
        $assignments = DB::table('services')
            ->select('branch_id', 'id as service_id')
            ->whereNotNull('branch_id')
            ->get()
            ->map(fn (object $row) => [
                'branch_id' => $row->branch_id,
                'service_id' => $row->service_id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->all();

        if ($assignments !== []) {
            DB::table('branch_service')->insert($assignments);
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_service');
    }
};
