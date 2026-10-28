<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('macrodroid_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 32)->unique();
            $table->string('name')->default('Telefon SMS');
            $table->boolean('enabled')->default(false);
            $table->string('sender_phone', 32)->nullable();
            $table->text('webhook_url')->nullable();
            $table->char('token_hash', 64)->nullable();
            $table->unsignedInteger('generation')->default(1);
            $table->unsignedInteger('lock_version')->default(0);
            $table->unsignedSmallInteger('per_minute')->default(6);
            $table->unsignedSmallInteger('per_day')->default(200);
            $table->unsignedSmallInteger('lifetime_minutes')->default(60);
            $table->unsignedTinyInteger('max_parts')->default(5);
            $table->timestamp('last_seen_at')->nullable();
            $table->uuid('probe_id')->nullable();
            $table->timestamp('probe_expires_at')->nullable();
            $table->timestamp('probe_confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sms_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('body_template');
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });
        Schema::create('order_sms_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('sms_template_id')->nullable()->constrained('sms_templates')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('macrodroid_devices')->nullOnDelete();
            $table->foreignId('automation_run_id')->nullable()->constrained('automation_runs')->nullOnDelete();
            $table->string('source', 16);
            $table->string('status', 24)->default('queued');
            $table->uuid('idempotency_key')->unique();
            $table->uuid('batch_key')->index('sms_batch_idx');
            $table->char('intent_hash', 64);
            $table->char('context_hash', 64)->nullable();
            $table->boolean('customer_recipient')->default(false);
            $table->boolean('uses_portal')->default(false);
            $table->unsignedInteger('device_generation');
            $table->text('recipient');
            $table->text('body');
            $table->string('template_name_snapshot', 120)->nullable();
            $table->string('actor_name', 120);
            $table->string('encoding', 12);
            $table->unsignedSmallInteger('units');
            $table->unsignedTinyInteger('parts');
            $table->char('claim_hash', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->string('safe_error', 64)->nullable();
            $table->timestamps();
            $table->index(['device_id', 'status', 'expires_at'], 'sms_device_pending_idx');
            $table->index(['device_id', 'claimed_at'], 'sms_device_rate_idx');
            $table->index(['order_id', 'id'], 'sms_order_history_idx');
        });
    }

    public function down(): void
    {
        foreach (['order_sms_messages', 'sms_templates', 'macrodroid_devices'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Nie mozna usunac zapisanej konfiguracji lub historii SMS.');
            }
        }
        Schema::dropIfExists('order_sms_messages');
        Schema::dropIfExists('sms_templates');
        Schema::dropIfExists('macrodroid_devices');
    }
};
