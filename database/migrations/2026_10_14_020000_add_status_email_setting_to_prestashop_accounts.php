<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestashop_accounts', function (Blueprint $table): void {
            $table->boolean('notify_customer_on_status_change')
                ->default(false)
                ->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('prestashop_accounts', function (Blueprint $table): void {
            $table->dropColumn('notify_customer_on_status_change');
        });
    }
};
