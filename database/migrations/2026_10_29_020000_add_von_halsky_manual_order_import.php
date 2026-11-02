<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('von_halsky_accounts', function (Blueprint $table): void {
            $table->foreignId('initial_status_setting_id')->nullable()
                ->constrained('order_status_settings')->nullOnDelete();
            $table->decimal('default_vat_rate', 5, 2)->nullable();
        });
        Schema::create('von_halsky_order_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('von_halsky_account_id')->nullable()->constrained('von_halsky_accounts')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->unique('vh_order_link_order_unique')->constrained('orders')->nullOnDelete();
            $table->string('environment', 16);
            $table->uuid('organization_id');
            $table->string('external_order_id', 255);
            $table->unique(['environment', 'organization_id', 'external_order_id'], 'vh_order_identity_unique');
            $table->string('account_name', 120);
            $table->char('source_hash', 64);
            $table->string('external_status', 32);
            $table->boolean('invoice_required')->default(false);
            $table->text('buyer_message')->nullable();
            $table->string('buyer_name')->nullable();
            $table->text('payment_evidence');
            $table->decimal('channel_paid_amount', 12, 2);
            $table->timestamp('channel_paid_at');
            $table->json('quantities_snapshot');
            $table->boolean('automations_suppressed')->default(true);
            $table->timestamp('imported_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('von_halsky_order_links')->exists()
            || DB::table('von_halsky_accounts')->whereNotNull('initial_status_setting_id')->exists()
            || DB::table('von_halsky_accounts')->whereNotNull('default_vat_rate')->exists()) {
            throw new RuntimeException('Nie mozna usunac historii importu lub ustawien Von Halsky.');
        }
        Schema::dropIfExists('von_halsky_order_links');
        Schema::table('von_halsky_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('initial_status_setting_id');
            $table->dropColumn('default_vat_rate');
        });
    }
};
