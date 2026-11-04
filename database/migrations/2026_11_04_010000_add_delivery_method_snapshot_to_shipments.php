<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('delivery_method_id')->nullable();
            $table->string('delivery_method_name')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('shipments')->whereNotNull('delivery_method_id')->orWhereNotNull('delivery_method_name')->exists()) {
            throw new RuntimeException('Cannot remove delivery method snapshots used by shipments.');
        }

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn(['delivery_method_id', 'delivery_method_name']);
        });
    }
};
