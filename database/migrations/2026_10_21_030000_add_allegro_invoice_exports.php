<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->string('invoice_sync_mode', 20)->default('disabled');
            $table->foreignId('invoice_sync_status_setting_id')->nullable()->constrained('order_status_settings')->nullOnDelete();
            $table->boolean('invoice_sync_only_required')->default(false);
            $table->string('invoice_sync_identity')->nullable();
            $table->unsignedBigInteger('invoice_sync_revision')->default(0);
        });
        Schema::create('allegro_invoice_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allegro_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('allegro_order_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 20);
            $table->string('seller_user_id');
            $table->uuid('external_order_id');
            $table->string('invoice_number');
            $table->string('fingerprint', 64)->unique();
            $table->boolean('manual')->default(false);
            $table->unsignedBigInteger('configuration_revision');
            $table->uuid('attempt_token');
            $table->string('state', 20);
            $table->text('error')->nullable();
            $table->string('document_hash', 64)->nullable();
            $table->string('pdf_checksum', 64)->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('remote_id')->nullable();
            $table->timestamp('upload_started_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedSmallInteger('verification_attempts')->default(0);
            $table->timestamps();
            $table->index(['order_id', 'state']);
            $table->index(['state', 'next_check_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allegro_invoice_exports');
        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invoice_sync_status_setting_id');
            $table->dropColumn(['invoice_sync_mode', 'invoice_sync_only_required', 'invoice_sync_identity', 'invoice_sync_revision']);
        });
    }
};
