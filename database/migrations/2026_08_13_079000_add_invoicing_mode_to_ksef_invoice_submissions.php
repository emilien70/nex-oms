<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_invoice_submissions', function (Blueprint $table): void {
            $table->string('invoicing_mode', 20)->nullable()->after('ksef_status_code');
        });
    }

    public function down(): void
    {
        Schema::table('ksef_invoice_submissions', function (Blueprint $table): void {
            $table->dropColumn('invoicing_mode');
        });
    }
};
