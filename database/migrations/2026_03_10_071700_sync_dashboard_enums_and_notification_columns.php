<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE staff_members SET employment_status = 'inactive' WHERE employment_status IN ('suspended', 'terminated')");
        DB::statement("ALTER TABLE staff_members MODIFY employment_status ENUM('active','inactive','on_leave') NOT NULL DEFAULT 'active'");

        DB::statement("UPDATE daily_queue_sessions SET session_status = 'live' WHERE session_status = 'open'");
        DB::statement("UPDATE daily_queue_sessions SET session_status = 'paused' WHERE session_status = 'closed'");
        DB::statement("ALTER TABLE daily_queue_sessions MODIFY session_status ENUM('live','closing_soon','paused') NOT NULL DEFAULT 'live'");

        DB::statement("UPDATE queue_entries SET queue_status = 'next' WHERE queue_status = 'called'");
        DB::statement("ALTER TABLE queue_entries MODIFY queue_status ENUM('waiting','next','serving','completed','cancelled') NOT NULL DEFAULT 'waiting'");

        DB::statement("UPDATE walk_in_tickets SET ticket_source = 'qr_scan' WHERE ticket_source = 'mobile'");
        DB::statement("UPDATE walk_in_tickets SET ticket_source = 'staff_assisted' WHERE ticket_source = 'staff'");
        DB::statement("ALTER TABLE walk_in_tickets MODIFY ticket_source ENUM('reception','kiosk','qr_scan','staff_assisted') NOT NULL");

        DB::statement("UPDATE walk_in_tickets SET ticket_status = 'queued' WHERE ticket_status = 'waiting'");
        DB::statement("UPDATE walk_in_tickets SET ticket_status = 'checked_in' WHERE ticket_status = 'called'");
        DB::statement("UPDATE walk_in_tickets SET ticket_status = 'completed' WHERE ticket_status = 'served'");
        DB::statement("UPDATE walk_in_tickets SET ticket_status = 'escalated' WHERE ticket_status = 'cancelled'");
        DB::statement("ALTER TABLE walk_in_tickets MODIFY ticket_status ENUM('queued','checked_in','serving','completed','escalated') NOT NULL DEFAULT 'queued'");

        DB::statement("UPDATE kiosk_devices SET device_status = 'online' WHERE device_status = 'active'");
        DB::statement("UPDATE kiosk_devices SET device_status = 'maintenance' WHERE device_status = 'offline'");
        DB::statement("ALTER TABLE kiosk_devices MODIFY device_status ENUM('online','busy','maintenance') NOT NULL DEFAULT 'online'");

        DB::statement("UPDATE qr_code_tokens SET token_status = 'consumed' WHERE token_status = 'used'");
        DB::statement("ALTER TABLE qr_code_tokens MODIFY token_status ENUM('active','consumed','expired') NOT NULL DEFAULT 'active'");

        DB::statement("UPDATE check_in_records SET check_in_result = 'pending' WHERE check_in_result = 'expired'");
        DB::statement("UPDATE check_in_records SET check_in_result = 'manual_assist' WHERE check_in_result = 'invalid'");
        DB::statement("ALTER TABLE check_in_records MODIFY check_in_result ENUM('success','pending','manual_assist') NOT NULL");

        DB::statement("UPDATE appointments SET appointment_status = 'pending' WHERE appointment_status = 'scheduled'");
        DB::statement("UPDATE appointments SET appointment_status = 'active' WHERE appointment_status = 'completed'");
        DB::statement("ALTER TABLE appointments MODIFY appointment_status ENUM('pending','confirmed','active','cancelled','no_show') NOT NULL DEFAULT 'pending'");

        DB::statement("ALTER TABLE notifications MODIFY notification_channel ENUM('in_app','sms','email','push') NOT NULL");

        if (Schema::hasColumn('notifications', 'notification_status') && ! Schema::hasColumn('notifications', 'delivery_status')) {
            DB::statement(
                "ALTER TABLE notifications CHANGE notification_status delivery_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending'"
            );
        }

        if (! Schema::hasColumn('notifications', 'read_at')) {
            DB::statement('ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL AFTER message_content');
        }
    }

    public function down(): void
    {
        // This migration repairs schema drift in existing MySQL databases.
        // Rolling it back would reintroduce incompatible enum sets for the current application code.
    }
};
