<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_series', function (Blueprint $table): void {
            $table->string('vat_rate_source', 30)->default('order_item');
            $table->decimal('default_vat_rate', 5, 2)->nullable();
            $table->boolean('include_shipping')->default(true);
            $table->string('shipping_vat_mode', 30)->default('highest_item');
            $table->decimal('default_shipping_vat_rate', 5, 2)->nullable();
            $table->boolean('skip_zero_price_items')->default(false);
            $table->string('payment_method_source', 30)->default('order');
            $table->string('fixed_payment_method', 80)->nullable();
            $table->string('sale_date_source', 30)->default('payment_or_issue');
            $table->string('payment_due_mode', 30)->default('none');
            $table->unsignedSmallInteger('payment_due_days')->nullable();
            $table->string('unit_price_mode', 20)->default('gross');
            $table->boolean('show_vat_column')->default(true);
            $table->boolean('show_order_number')->default(false);
            $table->boolean('show_buyer_signature')->default(false);
            $table->boolean('show_original_copy')->default(false);
            $table->string('print_template', 30)->default('standard');
            $table->string('primary_language', 30)->default('buyer_country');
            $table->string('secondary_language', 10)->nullable();
            $table->string('document_title', 120)->default('Faktura VAT');
            $table->unsignedTinyInteger('copies_count')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_series', function (Blueprint $table): void {
            $table->dropColumn([
                'vat_rate_source',
                'default_vat_rate',
                'include_shipping',
                'shipping_vat_mode',
                'default_shipping_vat_rate',
                'skip_zero_price_items',
                'payment_method_source',
                'fixed_payment_method',
                'sale_date_source',
                'payment_due_mode',
                'payment_due_days',
                'unit_price_mode',
                'show_vat_column',
                'show_order_number',
                'show_buyer_signature',
                'show_original_copy',
                'print_template',
                'primary_language',
                'secondary_language',
                'document_title',
                'copies_count',
            ]);
        });
    }
};
