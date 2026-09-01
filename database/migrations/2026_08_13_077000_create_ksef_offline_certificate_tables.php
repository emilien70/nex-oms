<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ksef_offline_certificates', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 20);
            $table->string('certificate_serial_number', 16);
            $table->string('label', 120)->nullable();
            $table->longText('certificate_pem');
            $table->longText('private_key_pem');
            $table->dateTimeTz('valid_from');
            $table->dateTimeTz('valid_until');
            $table->string('fingerprint_sha256', 64);
            $table->string('key_type', 10);
            $table->unsignedSmallInteger('key_size');
            $table->string('curve', 30)->nullable();
            $table->timestamps();

            $table->unique(
                ['environment', 'certificate_serial_number'],
                'ksef_offline_certificates_environment_serial_unique',
            );
        });

        Schema::create('ksef_offline_certificate_selections', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 20)->unique();
            $table->foreignId('offline_certificate_id')
                ->unique()
                ->constrained('ksef_offline_certificates')
                ->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ksef_offline_certificate_selections');
        Schema::dropIfExists('ksef_offline_certificates');
    }
};
