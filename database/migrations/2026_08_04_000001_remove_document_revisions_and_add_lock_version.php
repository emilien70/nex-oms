<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('invoice_revisions');

        if (Schema::hasColumn('invoices', 'revision_number')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropColumn('revision_number');
            });
        }

        if (! Schema::hasColumn('invoices', 'lock_version')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->unsignedInteger('lock_version')->default(1);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'lock_version')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropColumn('lock_version');
            });
        }

        if (! Schema::hasColumn('invoices', 'revision_number')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->unsignedInteger('revision_number')->default(1);
            });
        }

        // Rollback restores only the former structure; removed revision data cannot be recovered.
        if (! Schema::hasTable('invoice_revisions')) {
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
    }
};
