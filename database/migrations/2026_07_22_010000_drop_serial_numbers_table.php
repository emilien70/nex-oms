<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('serial_numbers');
    }

    public function down(): void
    {
        Schema::create('serial_numbers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->index()->constrained()->cascadeOnDelete();
            $table->string('serial_number')->unique();
            $table->string('source')->default('manual');
            $table->string('scanned_by')->nullable();
            $table->dateTime('scanned_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
};
