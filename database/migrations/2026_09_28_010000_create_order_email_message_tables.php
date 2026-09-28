<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_email_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('email_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('automation_run_id')->nullable()->constrained('automation_runs')->nullOnDelete();
            $table->foreignId('retry_of_message_id')
                ->nullable()
                ->constrained('order_email_messages')
                ->nullOnDelete();
            $table->string('source', 20)->index();
            $table->string('status', 20)->default('queued')->index();
            $table->uuid('idempotency_key')->unique();
            $table->string('message_id')->nullable()->unique();
            $table->json('actor_snapshot')->nullable();
            $table->string('template_name_snapshot', 160)->nullable();
            $table->string('account_name_snapshot', 160)->nullable();
            $table->string('from_email', 254);
            $table->string('from_name')->nullable();
            $table->string('reply_to_email', 254)->nullable();
            $table->string('to_email', 254);
            $table->string('to_name')->nullable();
            $table->string('subject');
            $table->string('format', 20);
            $table->longText('body_text');
            $table->longText('body_html')->nullable();
            $table->string('safe_error_code', 100)->nullable();
            $table->string('safe_error_message', 1000)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sending_started_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->index(['status', 'queued_at']);
            $table->index(['source', 'created_at']);
        });

        Schema::create('order_email_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_email_message_id')
                ->constrained('order_email_messages')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('kind', 40);
            $table->string('disk', 50)->default('local');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('file_size');
            $table->char('checksum_sha256', 64);
            $table->json('source_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['order_email_message_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_email_message_attachments');
        Schema::dropIfExists('order_email_messages');
    }
};
