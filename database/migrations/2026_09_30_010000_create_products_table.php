<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->string('ean', 14)->nullable()->unique();
            $table->decimal('price_gross', 15, 2);
            $table->char('currency', 3)->default('PLN');
            $table->decimal('vat_rate', 5, 2)->default(23);
            $table->integer('stock_quantity')->default(0);
            $table->decimal('package_weight_kg', 10, 3)->nullable();
            $table->decimal('package_length_cm', 10, 2)->nullable();
            $table->decimal('package_width_cm', 10, 2)->nullable();
            $table->decimal('package_height_cm', 10, 2)->nullable();
            $table->string('image_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('image_mime_type', 100)->nullable();
            $table->char('image_checksum', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
