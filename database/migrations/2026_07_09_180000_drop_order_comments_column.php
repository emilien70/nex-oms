<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $column = implode('_', ['comments', 'text']);

        if (Schema::hasColumn('orders', $column)) {
            Schema::table('orders', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }

    public function down(): void
    {
        $column = implode('_', ['comments', 'text']);

        if (! Schema::hasColumn('orders', $column)) {
            Schema::table('orders', function (Blueprint $table) use ($column) {
                $table->text($column)->nullable();
            });
        }
    }
};
