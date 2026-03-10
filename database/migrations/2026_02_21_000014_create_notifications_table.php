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
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Dependency data: notifications are user-owned and should be deleted with the user.
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('notification_type');
            $table->enum('notification_channel', ['in_app', 'sms', 'email', 'push']);
            $table->enum('delivery_status', ['pending', 'sent', 'failed'])->default('pending');
            $table->text('message_content');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('notification_type');
            $table->index('delivery_status');
            $table->index(['user_id', 'delivery_status']);
            $table->index(['user_id', 'read_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
