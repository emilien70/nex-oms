<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_return_id')->nullable();
            $table->unique('order_return_id', 'invoices_return_unique');
            $table->foreign('order_return_id', 'invoices_return_fk')->references('id')->on('order_returns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Powiazania Korekt ze zwrotami wymagaja zachowania. Automatyczny rollback jest zablokowany.');
    }
};
