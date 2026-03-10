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
        Schema::create('qr_code_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token_value')->unique();
            $table->dateTime('expiration_date_time');
            $table->dateTime('used_date_time')->nullable();
            $table->enum('token_status', ['active', 'consumed', 'expired'])->default('active');

            // Dependency data: token should be removed when its appointment is removed.
            $table->foreignUuid('appointment_id')
                ->nullable()
                ->constrained('appointments')
                ->cascadeOnDelete();

            // Dependency data: token should be removed when its ticket is removed.
            $table->foreignUuid('ticket_id')
                ->nullable()
                ->constrained('walk_in_tickets')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->index('token_value');
            $table->index('expiration_date_time');
            $table->index('token_status');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(
                <<<SQL
                CREATE TRIGGER trg_qr_code_tokens_single_source_insert
                BEFORE INSERT ON qr_code_tokens
                FOR EACH ROW
                BEGIN
                    IF NEW.appointment_id IS NOT NULL AND NEW.ticket_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'qr_code_tokens requires appointment_id or ticket_id, not both';
                    END IF;
                END
                SQL
            );

            DB::unprepared(
                <<<SQL
                CREATE TRIGGER trg_qr_code_tokens_single_source_update
                BEFORE UPDATE ON qr_code_tokens
                FOR EACH ROW
                BEGIN
                    IF NEW.appointment_id IS NOT NULL AND NEW.ticket_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'qr_code_tokens requires appointment_id or ticket_id, not both';
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
        DB::unprepared('DROP TRIGGER IF EXISTS trg_qr_code_tokens_single_source_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_qr_code_tokens_single_source_update');
        Schema::dropIfExists('qr_code_tokens');
    }
};
