<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->boolean('refund_write_scope_requested')->default(false);
            $table->boolean('refund_write_enabled')->default(false);
            $table->string('refund_write_identity')->nullable();
            $table->unsignedBigInteger('refund_write_revision')->default(0);
        });
        Schema::create('allegro_refund_commands', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('allegro_account_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_return_id')->nullable();
            $table->unsignedBigInteger('allegro_payment_refund_id')->nullable();
            $table->foreign('allegro_account_id', 'arc_account_fk')->references('id')->on('allegro_accounts')->nullOnDelete();
            $table->foreign('order_id', 'arc_order_fk')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('order_return_id', 'arc_return_fk')->references('id')->on('order_returns')->nullOnDelete();
            $table->foreign('allegro_payment_refund_id', 'arc_refund_fk')->references('id')->on('allegro_payment_refunds')->restrictOnDelete();
            $table->unsignedBigInteger('return_number');
            $table->string('environment', 20);
            $table->string('seller_user_id', 100);
            $table->uuid('external_order_id');
            $table->uuid('external_payment_id');
            $table->uuid('command_id')->unique('arc_command_unique');
            $table->uuid('external_refund_id')->nullable();
            $table->char('session_hash', 64);
            $table->char('context_hash', 64);
            $table->char('payload_hash', 64);
            $table->json('payload');
            $table->json('selection');
            $table->unsignedBigInteger('configuration_revision');
            $table->unsignedBigInteger('return_version');
            $table->decimal('products_value', 18, 2);
            $table->decimal('delivery_value', 18, 2);
            $table->decimal('total_value', 18, 2);
            $table->char('currency', 3);
            $table->string('state', 30)->default('draft');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['environment', 'seller_user_id', 'external_order_id'], 'arc_order_identity_index');
            $table->index(['state', 'next_check_at'], 'arc_due_index');
            $table->unique('allegro_payment_refund_id', 'arc_refund_unique');
            $table->unique(['environment', 'seller_user_id', 'external_refund_id'], 'arc_external_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Historia rzeczywistych zwrotow wplat wymaga zachowania. Automatyczny rollback jest zablokowany.');
    }
};
