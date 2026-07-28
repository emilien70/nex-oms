<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->timestamp('proforma_superseded_at')->nullable()->index();
            $table->foreignId('superseded_by_invoice_id')
                ->nullable()
                ->constrained('invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['superseded_by_invoice_id']);
            $table->dropColumn(['proforma_superseded_at', 'superseded_by_invoice_id']);
        });
    }
};
