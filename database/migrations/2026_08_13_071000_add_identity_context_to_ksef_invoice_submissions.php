<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_invoice_submissions', function (Blueprint $table): void {
            $table->string('context_nip', 10)->nullable()->after('environment');
            $table->string('seller_nip', 10)->nullable()->after('context_nip');
        });
    }

    public function down(): void
    {
        Schema::table('ksef_invoice_submissions', function (Blueprint $table): void {
            $table->dropColumn(['context_nip', 'seller_nip']);
        });
    }
};
