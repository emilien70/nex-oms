<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->char('code', 3)->primary();
            $table->string('name');
            $table->char('nbp_table', 1)->nullable();
        });

        DB::table('currencies')->insertOrIgnore([
            'code' => 'PLN',
            'name' => 'PLN',
            'nbp_table' => null,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
