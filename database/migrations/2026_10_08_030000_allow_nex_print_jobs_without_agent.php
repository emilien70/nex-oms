<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nex_print_jobs', function (Blueprint $table): void {
            $table->dropForeign(['agent_id']);
        });

        Schema::table('nex_print_jobs', function (Blueprint $table): void {
            $table->unsignedBigInteger('agent_id')->nullable()->change();
            $table->foreign('agent_id')
                ->references('id')
                ->on('nex_printer_agents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('nex_print_jobs')->whereNull('agent_id')->exists()) {
            throw new \RuntimeException(
                'Nie można cofnąć migracji: istnieją historyczne zadania po usuniętych komputerach NEX Printer.',
            );
        }

        Schema::table('nex_print_jobs', function (Blueprint $table): void {
            $table->dropForeign(['agent_id']);
        });

        Schema::table('nex_print_jobs', function (Blueprint $table): void {
            $table->unsignedBigInteger('agent_id')->nullable(false)->change();
            $table->foreign('agent_id')
                ->references('id')
                ->on('nex_printer_agents')
                ->restrictOnDelete();
        });
    }
};
