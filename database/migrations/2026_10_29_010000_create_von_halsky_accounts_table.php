<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('von_halsky_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key', 32)->unique('von_halsky_account_key_unique');
            $table->string('name', 120)->default('Von Halsky');
            $table->string('environment', 16)->default('stage');
            $table->uuid('organization_id')->nullable();
            $table->string('client_id', 255)->nullable();
            $table->text('client_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('lock_version')->default(0);
            $table->unsignedInteger('connection_revision')->default(0);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 16)->nullable();
            $table->string('last_test_message', 1000)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('von_halsky_accounts')->exists()) {
            throw new RuntimeException('Nie mozna usunac zapisanej konfiguracji Von Halsky.');
        }

        Schema::dropIfExists('von_halsky_accounts');
    }
};
