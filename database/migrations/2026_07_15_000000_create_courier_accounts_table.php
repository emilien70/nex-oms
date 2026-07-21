<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->index();
            $table->string('name');
            $table->string('environment')->default('sandbox');
            $table->text('api_token')->nullable();
            $table->string('organization_id')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_accounts');
    }
};
