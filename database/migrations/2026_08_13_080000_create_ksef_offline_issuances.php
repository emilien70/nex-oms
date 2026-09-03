<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ksef_offline_issuances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('environment', 20);
            $table->string('procedure', 32);
            $table->date('issue_date');
            $table->dateTimeTz('issued_at');
            $table->string('seller_nip', 10);
            $table->string('context_identifier_type', 20);
            $table->string('context_identifier_value', 80);
            $table->string('schema_id', 64);
            $table->longText('payload_xml');
            $table->string('invoice_hash', 44);
            $table->unsignedBigInteger('invoice_size');
            $table->foreignId('offline_certificate_id')
                ->nullable()
                ->constrained('ksef_offline_certificates')
                ->nullOnDelete();
            $table->string('certificate_serial_number', 16);
            $table->string('certificate_fingerprint_sha256', 64);
            $table->dateTimeTz('certificate_valid_from');
            $table->dateTimeTz('certificate_valid_until');
            $table->string('certificate_remote_status', 50);
            $table->dateTimeTz('certificate_remote_valid_from');
            $table->dateTimeTz('certificate_remote_valid_until');
            $table->dateTimeTz('certificate_remote_verified_at');
            $table->text('invoice_verification_url');
            $table->text('certificate_verification_url');
            $table->timestamps();

            $table->unique(
                ['invoice_id', 'environment'],
                'ksef_offline_issuance_environment_unique',
            );
            $table->index(
                ['environment', 'procedure', 'issued_at'],
                'ksef_offline_issuance_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ksef_offline_issuances');
    }
};
