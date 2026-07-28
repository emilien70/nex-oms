<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_counter_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_number_counter_id')
                ->constrained('invoice_number_counters')
                ->cascadeOnDelete();
            $table->string('numbering_period_key_snapshot', 30);
            $table->string('series_name_snapshot', 120)->nullable();
            $table->string('number_format_snapshot', 120)->nullable();
            $table->unsignedBigInteger('previous_last_sequence_number');
            $table->unsignedBigInteger('new_last_sequence_number');
            $table->unsignedBigInteger('previous_protected_floor_sequence_number');
            $table->unsignedBigInteger('new_protected_floor_sequence_number');
            $table->unsignedBigInteger('previous_next_sequence_number');
            $table->unsignedBigInteger('new_next_sequence_number');
            $table->text('reason');
            $table->json('actor_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_counter_adjustments');
    }
};
