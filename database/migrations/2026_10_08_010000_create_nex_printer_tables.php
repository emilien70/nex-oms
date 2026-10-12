<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nex_printer_pairing_codes', function (Blueprint $table): void {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('nex_printer_agents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name', 120);
            $table->string('platform', 40)->default('windows');
            $table->string('client_version', 40)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('paired_at');
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('nex_printers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained('nex_printer_agents')->cascadeOnDelete();
            $table->string('system_name');
            $table->string('display_name');
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_available')->default(true)->index();
            $table->json('capabilities')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'system_name']);
        });

        Schema::create('nex_print_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('agent_id')->constrained('nex_printer_agents')->restrictOnDelete();
            $table->foreignId('printer_id')->nullable()->constrained('nex_printers')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('document_type', 30);
            $table->string('document_number')->nullable();
            $table->string('printer_name_snapshot');
            $table->string('payload_disk', 50)->default('local');
            $table->string('payload_path');
            $table->string('payload_filename');
            $table->string('content_type', 100)->default('application/pdf');
            $table->char('payload_sha256', 64);
            $table->unsignedBigInteger('payload_size');
            $table->unsignedSmallInteger('copies')->default(1);
            $table->string('status', 30)->default('queued')->index();
            $table->string('origin', 30)->default('manual')->index();
            $table->uuid('idempotency_key')->unique();
            $table->char('lease_token_hash', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('spooled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->string('last_error_code', 100)->nullable();
            $table->string('last_error_message', 1000)->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status', 'created_at']);
            $table->index(['invoice_id', 'created_at']);
        });

        Schema::create('nex_print_job_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('print_job_id')->constrained('nex_print_jobs')->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->string('status', 30);
            $table->string('actor', 30);
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['print_job_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nex_print_job_events');
        Schema::dropIfExists('nex_print_jobs');
        Schema::dropIfExists('nex_printers');
        Schema::dropIfExists('nex_printer_agents');
        Schema::dropIfExists('nex_printer_pairing_codes');
    }
};
