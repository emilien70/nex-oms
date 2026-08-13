<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_credentials', function (Blueprint $table): void {
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_valid_until')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('refresh_token_valid_until')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->text('last_test_message')->nullable();
            $table->boolean('last_test_invoice_write')->nullable();
            $table->text('last_system_warning')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ksef_credentials', function (Blueprint $table): void {
            $table->dropColumn([
                'access_token',
                'access_token_valid_until',
                'refresh_token',
                'refresh_token_valid_until',
                'last_tested_at',
                'last_test_status',
                'last_test_message',
                'last_test_invoice_write',
                'last_system_warning',
            ]);
        });
    }
};
