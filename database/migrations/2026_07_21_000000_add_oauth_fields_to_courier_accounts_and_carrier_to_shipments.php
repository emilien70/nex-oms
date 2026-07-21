<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_accounts', function (Blueprint $table): void {
            $table->text('api_secret')->nullable()->after('api_token');
            $table->text('api_refresh_token')->nullable()->after('api_secret');
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('carrier_code', 64)->nullable()->after('provider')->index();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex(['carrier_code']);
            $table->dropColumn('carrier_code');
        });

        Schema::table('courier_accounts', function (Blueprint $table): void {
            $table->dropColumn(['api_secret', 'api_refresh_token']);
        });
    }
};
