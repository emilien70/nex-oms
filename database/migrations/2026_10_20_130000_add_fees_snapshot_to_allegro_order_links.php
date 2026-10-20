<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allegro_order_links', function (Blueprint $table) {
            $table->json('fees_snapshot')->nullable();
            $table->timestamp('fees_refreshed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('allegro_order_links', function (Blueprint $table) {
            $table->dropColumn(['fees_snapshot', 'fees_refreshed_at']);
        });
    }
};
