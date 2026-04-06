<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_in_records', function (Blueprint $table) {
            $table->dropForeign(['kiosk_id']);
        });

        Schema::table('check_in_records', function (Blueprint $table) {
            $table->uuid('kiosk_id')->nullable()->change();
        });

        Schema::table('check_in_records', function (Blueprint $table) {
            $table->foreign('kiosk_id')
                ->references('id')
                ->on('kiosk_devices')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('check_in_records', function (Blueprint $table) {
            $table->dropForeign(['kiosk_id']);
        });

        Schema::table('check_in_records', function (Blueprint $table) {
            $table->uuid('kiosk_id')->nullable(false)->change();
        });

        Schema::table('check_in_records', function (Blueprint $table) {
            $table->foreign('kiosk_id')
                ->references('id')
                ->on('kiosk_devices')
                ->restrictOnDelete();
        });
    }
};
