<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_document_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 20);
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps();

            $table->unique(['order_id', 'document_type']);
            $table->index('order_id');
            $table->index('document_type');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_document_slots');
    }
};
