<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_credentials', function (Blueprint $table): void {
            $table->longText('authentication_certificate')->nullable();
            $table->longText('authentication_private_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ksef_credentials', function (Blueprint $table): void {
            $table->dropColumn([
                'authentication_certificate',
                'authentication_private_key',
            ]);
        });
    }
};
