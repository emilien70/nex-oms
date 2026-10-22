<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allegro_commission_refund_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('allegro_account_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_return_id')->nullable();
            $table->foreign('allegro_account_id', 'acrc_account_fk')->references('id')->on('allegro_accounts')->nullOnDelete();
            $table->foreign('order_id', 'acrc_order_fk')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('order_return_id', 'acrc_return_fk')->references('id')->on('order_returns')->nullOnDelete();
            $table->unsignedBigInteger('return_number');
            $table->string('environment', 20);
            $table->string('seller_user_id', 100);
            $table->uuid('external_order_id');
            $table->uuid('line_item_id');
            $table->string('offer_id', 100);
            $table->string('buyer_id', 100);
            $table->text('product_name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('purchased_quantity');
            $table->uuid('request_key');
            $table->char('context_hash', 64);
            $table->char('account_identity', 64);
            $table->unsignedBigInteger('return_version');
            $table->timestamp('expires_at');
            $table->string('state', 30)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->uuid('external_claim_id')->nullable();
            $table->unsignedInteger('claimed_quantity')->nullable();
            $table->string('status', 100)->nullable();
            $table->string('claim_type', 30)->nullable();
            $table->timestamp('submitted_at', 6)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['environment', 'seller_user_id', 'line_item_id'], 'acrc_line_unique');
            $table->unique(['environment', 'seller_user_id', 'external_claim_id'], 'acrc_external_unique');
            $table->index(['environment', 'seller_user_id', 'external_order_id'], 'acrc_order_index');
            $table->index(['state', 'next_check_at'], 'acrc_due_index');
            $table->index('return_number', 'acrc_return_number_index');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Historia wnioskow o zwrot prowizji wymaga zachowania. Automatyczny rollback jest zablokowany.');
    }
};
