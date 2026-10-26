<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paynow_short_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paynow_payment_id')->nullable();
            $table->foreign('paynow_payment_id', 'paynow_short_payment_fk')
                ->references('id')->on('paynow_payments')->nullOnDelete();
            $table->unique('paynow_payment_id', 'paynow_short_payment_unique');
            $table->char('token_hash', 64)->unique('paynow_short_token_unique');
            $table->text('token');
            $table->char('identity_hash', 64);
            $table->char('target_hash', 64);
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('paynow_short_links') && DB::table('paynow_short_links')->exists()) {
            throw new RuntimeException('Nie mozna usunac zapisanych linkow PayNow.');
        }

        Schema::dropIfExists('paynow_short_links');
    }
};
