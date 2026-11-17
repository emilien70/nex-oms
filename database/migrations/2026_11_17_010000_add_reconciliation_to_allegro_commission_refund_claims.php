<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allegro_commission_refund_claims', function (Blueprint $table): void {
            $table->uuid('resolved_claim_id')->nullable();
            $table->boolean('lookup_via_list')->default(false);
            $table->unsignedTinyInteger('lookup_misses')->default(0);
            $table->unique(['environment', 'seller_user_id', 'resolved_claim_id'], 'acrc_resolved_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Cofnięcie migracji usunęłoby historię powiązania wniosków o zwrot prowizji.');
    }
};
