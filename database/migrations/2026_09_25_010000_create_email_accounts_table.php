<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('email');
            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port');
            $table->string('smtp_username');
            $table->text('smtp_password')->nullable();
            $table->string('encryption', 20)->default('starttls');
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->string('last_test_message', 1000)->nullable();
            $table->timestamps();

            $table->index(['email', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
