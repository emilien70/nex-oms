<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->uuid('execution_token')->nullable();
            $table->timestamp('execution_expires_at')->nullable();
            $table->index(['status', 'execution_expires_at'], 'automation_runs_execution_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->dropIndex('automation_runs_execution_expiry_index');
            $table->dropColumn(['execution_token', 'execution_expires_at']);
        });
    }
};
