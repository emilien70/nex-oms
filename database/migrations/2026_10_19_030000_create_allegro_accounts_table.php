<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allegro_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key')->unique();
            $table->string('name');
            $table->string('environment')->default('production');
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->json('granted_scopes')->nullable();
            $table->string('allegro_user_id')->nullable()->index();
            $table->string('allegro_login')->nullable();
            $table->string('marketplace_id')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::table('courier_accounts', function (Blueprint $table): void {
            $table->foreignId('allegro_account_id')
                ->nullable()
                ->after('provider')
                ->constrained('allegro_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courier_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('allegro_account_id');
        });

        Schema::dropIfExists('allegro_accounts');
    }
};
