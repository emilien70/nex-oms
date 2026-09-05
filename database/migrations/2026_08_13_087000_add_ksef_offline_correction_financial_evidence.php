<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_offline_issuances', function (Blueprint $table): void {
            $table->longText('correction_financial_evidence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ksef_offline_issuances', function (Blueprint $table): void {
            $table->dropColumn('correction_financial_evidence');
        });
    }
};
