<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_invoice_submissions', function (Blueprint $table): void {
            // NULL deliberately identifies history, never proof that no POST occurred.
            $table->unsignedSmallInteger('execution_protocol_version')->nullable();
            $table->string('execution_owner', 64)->nullable();
            $table->timestamp('execution_expires_at')->nullable();
            $table->timestamp('invoice_post_started_at')->nullable();
            $table->string('recovery_code', 100)->nullable();
            $table->timestamp('recovered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ksef_invoice_submissions', function (Blueprint $table): void {
            $table->dropColumn([
                'execution_protocol_version', 'execution_owner', 'execution_expires_at',
                'invoice_post_started_at', 'recovery_code', 'recovered_at',
            ]);
        });
    }
};
