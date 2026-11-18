<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paynow_order_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders', indexName: 'pol_order_fk')->nullOnDelete();
            $table->unique('order_id', 'pol_order_unique');
            $table->foreignId('paynow_short_link_id')->nullable()->constrained('paynow_short_links', indexName: 'pol_short_fk')->nullOnDelete();
            $table->char('token_hash', 64)->unique('pol_token_unique');
            $table->text('token');
            $table->char('buyer_hash', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('paynow_order_links')->exists()) {
            throw new RuntimeException('Cofnięcie migracji usunęłoby udostępnione linki do płatności.');
        }

        Schema::dropIfExists('paynow_order_links');
    }
};
