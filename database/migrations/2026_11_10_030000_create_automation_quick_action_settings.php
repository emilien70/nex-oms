<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_quick_action_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedTinyInteger('max_columns');
        });

        DB::table('automation_quick_action_settings')->insert(['id' => 1, 'max_columns' => 4]);
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_quick_action_settings');
    }
};
