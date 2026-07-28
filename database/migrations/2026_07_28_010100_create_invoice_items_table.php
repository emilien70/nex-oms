<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->foreignId('source_invoice_item_id')->nullable()->constrained('invoice_items')->restrictOnDelete();

            $table->string('line_type', 20)->default('product');
            $table->unsignedInteger('position')->default(1);

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit_name', 30)->nullable();
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_price_net', 15, 4)->default(0);
            $table->decimal('unit_price_gross', 15, 4)->default(0);
            $table->decimal('total_net', 15, 2)->default(0);
            $table->decimal('total_vat', 15, 2)->default(0);
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->string('vat_code', 20)->nullable();
            $table->json('gtu_codes')->nullable();
            $table->json('product_snapshot')->nullable();
            $table->json('metadata')->nullable();

            $table->json('correction_before_snapshot')->nullable();
            $table->json('correction_after_snapshot')->nullable();
            $table->json('correction_difference_snapshot')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('order_item_id');
            $table->index('product_id');
            $table->index('source_invoice_item_id');
            $table->index('line_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
