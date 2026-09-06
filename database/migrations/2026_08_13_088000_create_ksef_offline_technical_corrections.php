<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ksef_offline_technical_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('offline_issuance_id')->constrained('ksef_offline_issuances')->restrictOnDelete();
            $table->foreignId('rejected_submission_id')->constrained('ksef_invoice_submissions')->restrictOnDelete();
            $table->string('environment', 20);
            $table->string('context_nip', 10);
            $table->string('seller_nip', 10);
            $table->string('schema_id', 64);
            $table->dateTimeTz('generated_at');
            $table->longText('payload_xml');
            $table->string('invoice_hash', 44);
            $table->unsignedBigInteger('invoice_size');
            $table->string('hash_of_corrected_invoice', 44);
            $table->unsignedSmallInteger('source_status_code');
            $table->unsignedSmallInteger('eligibility_policy_version');
            $table->string('business_fingerprint', 44);
            $table->unsignedSmallInteger('business_fingerprint_version');
            $table->timestamps();

            $table->unique('rejected_submission_id', 'ksef_offline_technical_source_unique');
            $table->unique('offline_issuance_id', 'ksef_offline_technical_issuance_unique');
            $table->index(
                ['invoice_id', 'environment'],
                'ksef_offline_technical_invoice_environment_index',
            );
        });

        Schema::table('ksef_invoice_submissions', function (Blueprint $table): void {
            $table->foreignId('offline_technical_correction_id')
                ->nullable()
                ->after('offline_issuance_id')
                ->constrained('ksef_offline_technical_corrections')
                ->restrictOnDelete();
            $table->unique(
                'offline_technical_correction_id',
                'ksef_submission_offline_technical_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('ksef_invoice_submissions', function (Blueprint $table): void {
            $table->dropUnique('ksef_submission_offline_technical_unique');
            $table->dropConstrainedForeignId('offline_technical_correction_id');
        });

        Schema::dropIfExists('ksef_offline_technical_corrections');
    }
};
