<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CASH_ON_DELIVERY_SOURCE_KEY = '**cash_on_delivery**';

    public function up(): void
    {
        Schema::table('ksef_payment_method_mappings', function (Blueprint $table): void {
            $table->string('source_kind', 32)->default('payment_method')->after('id');
        });

        DB::table('ksef_payment_method_mappings')
            ->where('source_key', self::CASH_ON_DELIVERY_SOURCE_KEY)
            ->update(['source_kind' => 'cash_on_delivery']);

        Schema::table('ksef_payment_method_mappings', function (Blueprint $table): void {
            $table->dropUnique('ksef_payment_method_mappings_source_key_unique');
            $table->unique(
                ['source_kind', 'source_key'],
                'ksef_payment_method_source_unique',
            );
        });
    }

    public function down(): void
    {
        $hasCollisions = DB::table('ksef_payment_method_mappings')
            ->select('source_key')
            ->groupBy('source_key')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasCollisions) {
            throw new RuntimeException(
                'Nie można cofnąć migracji mapowań KSeF bez utraty kolidujących źródeł płatności.',
            );
        }

        Schema::table('ksef_payment_method_mappings', function (Blueprint $table): void {
            $table->dropUnique('ksef_payment_method_source_unique');
            $table->unique('source_key');
            $table->dropColumn('source_kind');
        });
    }
};
