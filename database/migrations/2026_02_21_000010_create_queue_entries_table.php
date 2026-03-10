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
        Schema::create('queue_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Dependency data: queue entries belong to a queue session and should be removed with it.
            $table->foreignUuid('queue_session_id')
                ->constrained('daily_queue_sessions')
                ->cascadeOnDelete();

            // Dependency data: queue entries belong to customers and should be removed with them.
            $table->foreignUuid('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            $table->unsignedInteger('queue_position');
            $table->enum('queue_status', ['waiting', 'next', 'serving', 'completed', 'cancelled'])
                ->default('waiting');
            $table->dateTime('checked_in_at')->nullable();

            // Operational history: keep queue records if serving staff is removed.
            $table->foreignUuid('served_by_staff_id')
                ->nullable()
                ->constrained('staff_members')
                ->nullOnDelete();

            // Operational history: keep queue records if linked appointment is removed.
            $table->foreignUuid('appointment_id')
                ->nullable()
                ->constrained('appointments')
                ->nullOnDelete();

            // Operational history: keep queue records if linked ticket is removed.
            $table->foreignUuid('ticket_id')
                ->nullable()
                ->constrained('walk_in_tickets')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['queue_session_id', 'queue_position']);
            $table->index('queue_session_id');
            $table->index('customer_id');
            $table->index('queue_status');
            $table->index(['queue_session_id', 'queue_status', 'queue_position']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE queue_entries ADD CONSTRAINT chk_queue_entries_position_positive CHECK (queue_position > 0)'
            );
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(
                <<<SQL
                CREATE TRIGGER trg_queue_entries_single_source_insert
                BEFORE INSERT ON queue_entries
                FOR EACH ROW
                BEGIN
                    IF NEW.appointment_id IS NOT NULL AND NEW.ticket_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'queue_entries requires appointment_id or ticket_id, not both';
                    END IF;
                END
                SQL
            );

            DB::unprepared(
                <<<SQL
                CREATE TRIGGER trg_queue_entries_single_source_update
                BEFORE UPDATE ON queue_entries
                FOR EACH ROW
                BEGIN
                    IF NEW.appointment_id IS NOT NULL AND NEW.ticket_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'queue_entries requires appointment_id or ticket_id, not both';
                    END IF;
                END
                SQL
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_queue_entries_single_source_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_queue_entries_single_source_update');
        Schema::dropIfExists('queue_entries');
    }
};
