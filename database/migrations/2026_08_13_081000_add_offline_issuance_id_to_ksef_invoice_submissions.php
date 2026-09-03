<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_invoice_submissions', function (Blueprint $table): void {
            $table->foreignId('offline_issuance_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('ksef_offline_issuances')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ksef_invoice_submissions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('offline_issuance_id');
        });
    }
};
