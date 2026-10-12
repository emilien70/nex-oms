<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nex_printers', function (Blueprint $table): void {
            $table->boolean('is_enabled')->default(true)->index();
        });
    }

    public function down(): void
    {
        Schema::table('nex_printers', function (Blueprint $table): void {
            $table->dropColumn('is_enabled');
        });
    }
};
