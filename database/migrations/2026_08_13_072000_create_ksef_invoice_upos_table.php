<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ksef_invoice_upos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ksef_invoice_submission_id')
                ->unique()
                ->constrained('ksef_invoice_submissions')
                ->restrictOnDelete();
            $table->string('schema_id', 32);
            $table->longText('payload_xml');
            $table->string('payload_hash', 44);
            $table->unsignedBigInteger('payload_size');
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ksef_invoice_upos');
    }
};
