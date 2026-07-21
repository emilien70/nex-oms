<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_parcels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('external_id')->nullable();
            $table->string('tracking_number')->nullable()->index();
            $table->decimal('weight', 8, 3);
            $table->decimal('length', 8, 2);
            $table->decimal('width', 8, 2);
            $table->decimal('height', 8, 2);
            $table->boolean('is_non_standard')->default(false);
            $table->timestamps();

            $table->unique(['shipment_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_parcels');
    }
};
