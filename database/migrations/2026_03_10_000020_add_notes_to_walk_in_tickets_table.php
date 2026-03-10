<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walk_in_tickets', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('ticket_status');
        });
    }

    public function down(): void
    {
        Schema::table('walk_in_tickets', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
