<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allegro_order_links', function (Blueprint $table) {
            $table->json('details_snapshot')->nullable();
            $table->timestamp('details_refreshed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('allegro_order_links', function (Blueprint $table) {
            $table->dropColumn(['details_snapshot', 'details_refreshed_at']);
        });
    }
};
