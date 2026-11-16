<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('archive_deferred_until')->nullable();
            $table->index(['archived_at', 'deleted_at', 'status', 'id'], 'orders_archive_status_idx');
            $table->index(['archived_at', 'deleted_at', 'id'], 'orders_archive_list_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_archive_status_idx');
            $table->dropIndex('orders_archive_list_idx');
            $table->dropColumn(['archived_at', 'archive_deferred_until']);
        });
    }
};
