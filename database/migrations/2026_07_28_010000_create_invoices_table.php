<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_series_id')->constrained('invoice_series')->restrictOnDelete();

            $table->string('document_type', 20);
            $table->string('status', 20)->default('draft');

            $table->string('number', 120)->nullable();
            $table->unsignedBigInteger('sequence_number')->nullable();
            $table->string('numbering_period_key', 30)->nullable();
            $table->string('number_format_snapshot', 120)->nullable();
            $table->string('series_name_snapshot', 120)->nullable();

            $table->date('issue_date')->nullable();
            $table->date('sale_date')->nullable();
            $table->date('payment_due_date')->nullable();
            $table->timestamp('issued_at')->nullable();

            $table->unsignedInteger('revision_number')->default(1);
            $table->string('source_snapshot_hash', 64)->nullable();
            $table->timestamp('last_refreshed_at')->nullable();

            $table->foreignId('corrected_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('previous_correction_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->text('correction_reason')->nullable();
            $table->json('correction_totals_snapshot')->nullable();

            $table->string('order_reference_snapshot')->nullable();
            $table->string('seller_name_snapshot')->nullable();
            $table->string('seller_tax_id_snapshot')->nullable();
            $table->string('buyer_name_snapshot')->nullable();
            $table->string('buyer_tax_id_snapshot')->nullable();
            $table->string('recipient_name_snapshot')->nullable();

            $table->json('seller_snapshot')->nullable();
            $table->json('buyer_snapshot')->nullable();
            $table->json('recipient_snapshot')->nullable();
            $table->json('issuer_snapshot')->nullable();
            $table->json('order_snapshot')->nullable();
            $table->json('payment_snapshot')->nullable();
            $table->json('shipping_snapshot')->nullable();
            $table->json('series_settings_snapshot')->nullable();
            $table->json('tax_summary_snapshot')->nullable();
            $table->json('tax_metadata_snapshot')->nullable();

            $table->text('additional_information_text')->nullable();

            $table->string('currency', 3)->default('PLN');
            $table->decimal('total_net', 15, 2)->default(0);
            $table->decimal('total_vat', 15, 2)->default(0);
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('amount_due', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['invoice_series_id', 'number']);
            $table->unique('previous_correction_id', 'invoices_previous_correction_unique');

            $table->index('order_id');
            $table->index('invoice_series_id');
            $table->index('document_type');
            $table->index('status');
            $table->index('issue_date');
            $table->index('issued_at');
            $table->index('corrected_invoice_id');
            $table->index('buyer_tax_id_snapshot');
            $table->index('last_refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
