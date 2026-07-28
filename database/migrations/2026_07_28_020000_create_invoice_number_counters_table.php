<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_series_id')
                ->constrained('invoice_series')
                ->cascadeOnDelete();
            $table->string('numbering_period_key', 30);
            $table->unsignedBigInteger('last_sequence_number')->default(0);
            $table->unsignedBigInteger('protected_floor_sequence_number')->default(0);
            $table->timestamps();

            $table->unique(
                ['invoice_series_id', 'numbering_period_key'],
                'invoice_number_counters_series_period_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_counters');
    }
};
