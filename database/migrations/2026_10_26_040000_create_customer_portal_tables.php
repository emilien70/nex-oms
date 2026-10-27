<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_portal_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 30)->unique('portal_settings_key_unique');
            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->string('store_name', 120)->default('NEX-OMS');
            $table->char('accent_color', 7)->default('#0075c9');
            $table->string('logo_path')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 60)->nullable();
            $table->text('customer_message')->nullable();
            $table->unsignedSmallInteger('availability_days')->default(90);
            $table->boolean('show_images')->default(true);
            $table->boolean('show_address')->default(false);
            $table->boolean('show_invoices')->default(true);
            $table->boolean('show_corrections')->default(true);
            $table->boolean('show_proformas')->default(false);
            $table->timestamps();
        });
        Schema::create('customer_portal_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_status_setting_id')->nullable();
            $table->foreign('order_status_setting_id', 'portal_status_setting_fk')->references('id')->on('order_status_settings')->nullOnDelete();
            $table->unique('order_status_setting_id', 'portal_status_setting_unique');
            $table->boolean('is_visible')->default(false);
            $table->string('public_name', 120)->nullable();
            $table->string('public_message', 1000)->nullable();
            $table->timestamps();
        });
        Schema::create('customer_portal_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->nullable();
            $table->foreign('order_id', 'portal_link_order_fk')->references('id')->on('orders')->nullOnDelete();
            $table->unique('order_id', 'portal_link_order_unique');
            $table->char('token_hash', 64)->unique('portal_link_token_unique');
            $table->text('token');
            $table->char('identity_hash', 64);
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedInteger('generation')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('customer_portal_links')->exists() || DB::table('customer_portal_settings')->exists()
            || DB::table('customer_portal_statuses')->exists()) {
            throw new RuntimeException('Nie mozna usunac zapisanych ustawien lub linkow portalu klienta.');
        }
        Schema::dropIfExists('customer_portal_links');
        Schema::dropIfExists('customer_portal_statuses');
        Schema::dropIfExists('customer_portal_settings');
    }
};
