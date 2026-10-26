<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestashop_order_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prestashop_order_link_id')->nullable();
            $table->foreign('prestashop_order_link_id', 'ps_order_messages_link_fk')
                ->references('id')->on('prestashop_order_links')->nullOnDelete();
            $table->unsignedBigInteger('external_message_id');
            $table->unsignedBigInteger('external_customer_id');
            $table->string('author_name');
            $table->text('message');
            $table->dateTime('source_created_at')->nullable();
            $table->timestamps();
            $table->unique(['prestashop_order_link_id', 'external_message_id'], 'ps_order_messages_external_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('prestashop_order_messages') && DB::table('prestashop_order_messages')->exists()) {
            throw new RuntimeException('Nie mozna usunac zapisanej historii komentarzy PrestaShop.');
        }
        Schema::dropIfExists('prestashop_order_messages');
    }
};
