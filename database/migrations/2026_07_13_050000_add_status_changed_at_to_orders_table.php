<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'status_changed_at')) {
                $table->dateTime('status_changed_at')->nullable()->after('status');
            }
        });

        if (Schema::hasColumn('orders', 'status_changed_at')) {
            DB::table('orders')
                ->whereNull('status_changed_at')
                ->update([
                    'status_changed_at' => DB::raw('COALESCE(updated_at, created_at)'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'status_changed_at')) {
                $table->dropColumn('status_changed_at');
            }
        });
    }
};
