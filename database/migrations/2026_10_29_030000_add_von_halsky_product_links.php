<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('von_halsky_product_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('von_halsky_account_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 20);
            $table->uuid('organization_id');
            $table->string('external_offer_id');
            $table->timestamps();
            $table->unique(['environment', 'organization_id', 'external_offer_id'], 'vh_product_offer_unique');
            $table->index(['product_id', 'von_halsky_account_id'], 'vh_product_account_index');
        });

        Schema::table('von_halsky_accounts', function (Blueprint $table): void {
            $table->boolean('use_linked_products')->default(false);
            $table->boolean('use_catalog_product_names')->default(false);
            $table->boolean('use_catalog_vat')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('von_halsky_product_links')->exists()
            || DB::table('von_halsky_accounts')->where('use_linked_products', true)
                ->orWhere('use_catalog_product_names', true)->orWhere('use_catalog_vat', true)->exists()) {
            throw new RuntimeException('Nie mozna usunac uzywanych powiazan lub ustawien katalogu Von Halsky.');
        }

        Schema::drop('von_halsky_product_links');
        Schema::table('von_halsky_accounts', function (Blueprint $table): void {
            $table->dropColumn(['use_linked_products', 'use_catalog_product_names', 'use_catalog_vat']);
        });
    }
};
