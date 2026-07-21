<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('color')->nullable();
            $table->string('short_name')->nullable();
            $table->string('full_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_settings');
    }
};
