<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestashop_order_states', function (Blueprint $table): void {
            $table->boolean('is_available')->default(true)->after('deleted');
        });

        Schema::create('prestashop_inbound_status_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prestashop_account_id');
            $table->unsignedBigInteger('external_order_state_id');
            $table->unsignedBigInteger('order_status_setting_id')->nullable();
            $table->timestamps();

            $table->foreign('prestashop_account_id', 'ps_in_status_account_fk')
                ->references('id')
                ->on('prestashop_accounts')
                ->cascadeOnDelete();
            $table->foreign('order_status_setting_id', 'ps_in_status_oms_fk')
                ->references('id')
                ->on('order_status_settings')
                ->nullOnDelete();
            $table->unique(
                ['prestashop_account_id', 'external_order_state_id'],
                'ps_in_status_account_state_unique',
            );
        });

        Schema::create('prestashop_outbound_status_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prestashop_account_id');
            $table->unsignedBigInteger('order_status_setting_id')->nullable();
            $table->unsignedBigInteger('external_order_state_id');
            $table->timestamps();

            $table->foreign('prestashop_account_id', 'ps_out_status_account_fk')
                ->references('id')
                ->on('prestashop_accounts')
                ->cascadeOnDelete();
            $table->foreign('order_status_setting_id', 'ps_out_status_oms_fk')
                ->references('id')
                ->on('order_status_settings')
                ->nullOnDelete();
            $table->unique(
                ['prestashop_account_id', 'order_status_setting_id'],
                'ps_out_status_account_oms_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestashop_outbound_status_mappings');
        Schema::dropIfExists('prestashop_inbound_status_mappings');

        Schema::table('prestashop_order_states', function (Blueprint $table): void {
            $table->dropColumn('is_available');
        });
    }
};
