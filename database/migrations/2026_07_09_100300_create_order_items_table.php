<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->string('ean')->nullable();
            $table->string('offer_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price_gross', 12, 2)->default(0);
            $table->decimal('total_price_gross', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
