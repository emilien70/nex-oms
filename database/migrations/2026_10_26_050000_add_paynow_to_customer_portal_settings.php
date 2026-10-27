<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_portal_settings', function (Blueprint $table): void {
            $table->boolean('paynow_enabled')->default(false);
            $table->boolean('paynow_retry_enabled')->default(false);
            $table->json('paynow_sources')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('customer_portal_settings')->where('paynow_enabled', true)
            ->orWhere('paynow_retry_enabled', true)->orWhereNotNull('paynow_sources')->exists()) {
            throw new RuntimeException('Nie mozna usunac skonfigurowanych ustawien platnosci portalu.');
        }

        Schema::table('customer_portal_settings', function (Blueprint $table): void {
            $table->dropColumn(['paynow_enabled', 'paynow_retry_enabled', 'paynow_sources']);
        });
    }
};
