<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_latarnia_sync_states', function (Blueprint $table): void {
            $table->dateTimeTz('messages_coverage_from_at')->nullable();
            $table->dateTimeTz('messages_coverage_through_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ksef_latarnia_sync_states', function (Blueprint $table): void {
            $table->dropColumn([
                'messages_coverage_from_at',
                'messages_coverage_through_at',
            ]);
        });
    }
};
