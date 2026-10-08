<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_saved_views', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->json('filters');
            $table->unsignedInteger('sort_order')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_saved_views');
    }
};
