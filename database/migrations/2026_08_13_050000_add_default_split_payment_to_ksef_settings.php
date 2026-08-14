<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_settings', function (Blueprint $table): void {
            $table->boolean('default_split_payment')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('ksef_settings', function (Blueprint $table): void {
            $table->dropColumn('default_split_payment');
        });
    }
};
