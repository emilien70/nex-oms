<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_custom_events', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 1000)->nullable();
            $table->unsignedInteger('sort_order');
            $table->boolean('button_enabled')->default(false);
            $table->string('button_label', 80)->nullable();
            $table->string('icon', 32);
            $table->char('color', 7);
            $table->boolean('confirmation_required')->default(false);
            $table->json('order_statuses')->nullable();
            $table->unsignedSmallInteger('button_position')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_custom_event_invocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_custom_event_id')->nullable()
                ->constrained(indexName: 'auto_custom_inv_event_fk')->nullOnDelete();
            $table->string('event_label', 120);
            $table->string('status', 16);
            $table->unsignedInteger('run_count')->default(0);
            $table->timestamps();
            $table->index(['order_id', 'automation_custom_event_id', 'created_at'], 'auto_custom_inv_order_event_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_custom_event_invocations');
        Schema::dropIfExists('automation_custom_events');
    }
};
