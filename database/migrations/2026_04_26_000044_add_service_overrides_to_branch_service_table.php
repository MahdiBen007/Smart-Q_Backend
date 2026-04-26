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
        Schema::table('branch_service', function (Blueprint $table) {
            $table->string('service_name_override')->nullable()->after('service_id');
            $table->string('service_subtitle_override')->nullable()->after('service_name_override');
            $table->text('service_description_override')->nullable()->after('service_subtitle_override');
            $table->string('service_icon_override')->nullable()->after('service_description_override');
            $table->unsignedInteger('average_service_duration_minutes_override')->nullable()->after('service_icon_override');
            $table->boolean('is_active_override')->nullable()->after('average_service_duration_minutes_override');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_service', function (Blueprint $table) {
            $table->dropColumn([
                'service_name_override',
                'service_subtitle_override',
                'service_description_override',
                'service_icon_override',
                'average_service_duration_minutes_override',
                'is_active_override',
            ]);
        });
    }
};
