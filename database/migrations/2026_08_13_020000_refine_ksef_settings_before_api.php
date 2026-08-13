<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_settings', function (Blueprint $table): void {
            $table->boolean('is_active')->default(false);
            $table->string('zero_vat_classification', 20)->default('wdt');
        });

        Schema::table('ksef_settings', function (Blueprint $table): void {
            $table->dropColumn('include_sale_date');
        });
    }

    public function down(): void
    {
        Schema::table('ksef_settings', function (Blueprint $table): void {
            $table->boolean('include_sale_date')->default(true);
        });

        Schema::table('ksef_settings', function (Blueprint $table): void {
            $table->dropColumn(['zero_vat_classification', 'is_active']);
        });
    }
};
