<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_api_logs', function (Blueprint $table): void {
            $table->string('response_trace_id')->nullable();
            $table->unsignedSmallInteger('attempt_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('integration_api_logs', function (Blueprint $table): void {
            $table->dropColumn(['response_trace_id', 'attempt_number']);
        });
    }
};
