<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_portal_links', function (Blueprint $table): void {
            $table->char('binding_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('customer_portal_links')->whereNotNull('binding_hash')->exists()) {
            throw new RuntimeException('Permanent customer portal bindings cannot be removed.');
        }

        Schema::table('customer_portal_links', function (Blueprint $table): void {
            $table->dropColumn('binding_hash');
        });
    }
};
