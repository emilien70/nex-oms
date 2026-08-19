<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ksef_invoice_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('environment', 20);
            $table->unsignedInteger('attempt_number');
            $table->string('status', 32);
            $table->string('schema_id', 64);
            $table->timestamp('generated_at');
            $table->longText('payload_xml');
            $table->string('invoice_hash', 44);
            $table->unsignedBigInteger('invoice_size');
            $table->string('public_key_id', 160)->nullable();
            $table->string('session_reference_number', 160)->nullable();
            $table->timestamp('session_valid_until')->nullable();
            $table->string('encrypted_invoice_hash', 44)->nullable();
            $table->unsignedBigInteger('encrypted_invoice_size')->nullable();
            $table->string('invoice_reference_number', 160)->nullable();
            $table->timestamp('session_closed_at')->nullable();
            $table->integer('ksef_status_code')->nullable();
            $table->string('ksef_number', 35)->nullable();
            $table->timestamp('acquisition_date')->nullable();
            $table->timestamp('invoicing_date')->nullable();
            $table->timestamp('permanent_storage_date')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('safe_error_code', 120)->nullable();
            $table->text('safe_error_message')->nullable();
            $table->string('session_close_error_code', 120)->nullable();
            $table->text('session_close_error_message')->nullable();
            $table->timestamps();

            $table->unique(
                ['invoice_id', 'environment', 'attempt_number'],
                'ksef_invoice_submission_attempt_unique',
            );
            $table->index(['invoice_id', 'environment', 'status'], 'ksef_invoice_submission_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ksef_invoice_submissions');
    }
};
