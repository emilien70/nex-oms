<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->json('document_snapshot');
            $table->json('items_snapshot');
            $table->string('source_snapshot_hash', 64);
            $table->string('source', 20);
            $table->json('actor_snapshot')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['invoice_id', 'revision_number']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_revisions');
    }
};
