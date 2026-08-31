<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ksef_invoice_provenances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('environment', 20);
            $table->string('provenance', 32);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(
                ['invoice_id', 'environment'],
                'ksef_invoice_provenance_environment_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ksef_invoice_provenances');
    }
};
