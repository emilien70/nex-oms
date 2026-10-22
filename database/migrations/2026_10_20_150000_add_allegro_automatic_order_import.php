<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->boolean('order_import_enabled')->default(false);
            $table->string('order_import_identity')->nullable();
            $table->string('order_event_cursor')->nullable();
            $table->timestamp('order_import_started_at')->nullable();
            $table->timestamp('last_order_scan_at')->nullable();
            $table->text('last_order_import_error')->nullable();
        });
        Schema::table('allegro_order_links', function (Blueprint $table): void {
            $table->string('import_state', 20)->default('complete')->index();
            $table->timestamp('completed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->boolean('delivery_cost_known')->default(true);
            $table->json('line_item_ids')->nullable();
            $table->string('buyer_name')->nullable();
            $table->timestamp('buyer_message_at')->nullable();
            $table->foreignId('replaced_by_link_id')->nullable()->constrained('allegro_order_links')->nullOnDelete();
        });
        DB::table('allegro_order_links')->update(['completed_at' => DB::raw('imported_at')]);
        Schema::create('allegro_order_import_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allegro_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 20);
            $table->string('seller_user_id');
            $table->string('external_event_id');
            $table->uuid('external_order_id');
            $table->string('event_type', 50);
            $table->timestamp('occurred_at');
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['environment', 'seller_user_id', 'external_event_id'], 'allegro_import_event_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allegro_order_import_events');
        Schema::table('allegro_order_links', function (Blueprint $table): void {
            $table->dropForeign(['replaced_by_link_id']);
            $table->dropIndex(['import_state']);
            $table->dropColumn(['import_state', 'completed_at', 'processing_error', 'delivery_cost_known', 'line_item_ids', 'buyer_name', 'buyer_message_at', 'replaced_by_link_id']);
        });
        Schema::table('allegro_accounts', fn (Blueprint $table) => $table->dropColumn(['order_import_enabled', 'order_import_identity', 'order_event_cursor', 'order_import_started_at', 'last_order_scan_at', 'last_order_import_error']));
    }
};
