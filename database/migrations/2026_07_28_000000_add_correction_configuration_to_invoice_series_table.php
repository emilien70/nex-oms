<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_series', function (Blueprint $table): void {
            $table->text('default_correction_reason')->nullable();
            $table->string('correction_sale_date_source', 30)->default('source_invoice');
            $table->string('correction_issuer_source', 30)->default('source_invoice');
            $table->string('correction_payment_method_source', 30)->default('source_invoice');
            $table->boolean('show_correction_item_sequence')->default(false);
            $table->boolean('show_return_id_in_header')->default(false);
            $table->boolean('show_payment_identifier')->default(false);
        });

        DB::table('invoice_series')
            ->where('document_type', 'correction')
            ->update([
                'correction_sale_date_source' => 'source_invoice',
                'correction_issuer_source' => 'source_invoice',
                'correction_payment_method_source' => 'source_invoice',
                'show_correction_item_sequence' => false,
                'show_return_id_in_header' => false,
                'show_payment_identifier' => false,
            ]);

        DB::table('invoice_series')
            ->where('document_type', 'correction')
            ->where(function ($query): void {
                $query->whereNull('document_title')
                    ->orWhereRaw("TRIM(document_title) = ''")
                    ->orWhere('document_title', 'Faktura VAT');
            })
            ->update(['document_title' => 'Faktura korygująca']);
    }

    public function down(): void
    {
        Schema::table('invoice_series', function (Blueprint $table): void {
            $table->dropColumn([
                'default_correction_reason',
                'correction_sale_date_source',
                'correction_issuer_source',
                'correction_payment_method_source',
                'show_correction_item_sequence',
                'show_return_id_in_header',
                'show_payment_identifier',
            ]);
        });
    }
};
