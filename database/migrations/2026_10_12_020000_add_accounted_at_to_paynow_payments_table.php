<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paynow_payments', function (Blueprint $table): void {
            $table->timestamp('accounted_at')->nullable()->after('confirmed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('paynow_payments', function (Blueprint $table): void {
            $table->dropIndex(['accounted_at']);
            $table->dropColumn('accounted_at');
        });
    }
};
