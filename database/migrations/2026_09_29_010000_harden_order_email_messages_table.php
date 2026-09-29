<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_email_messages', function (Blueprint $table): void {
            $table->char('intent_fingerprint', 64)->nullable()->after('idempotency_key');
            $table->char('smtp_account_fingerprint', 64)->nullable()->after('intent_fingerprint');
            $table->unsignedInteger('attachment_count')->nullable()->after('body_html');
            $table->char('attachment_manifest_sha256', 64)->nullable()->after('attachment_count');
        });
    }

    public function down(): void
    {
        Schema::table('order_email_messages', function (Blueprint $table): void {
            $table->dropColumn([
                'intent_fingerprint',
                'smtp_account_fingerprint',
                'attachment_count',
                'attachment_manifest_sha256',
            ]);
        });
    }
};
