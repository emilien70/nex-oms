<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jpk_taxpayer_profiles', function (Blueprint $table): void {
            $table->enum('singleton_key', ['default'])->primary()->default('default');
            $table->string('type', 20);
            $table->string('nip', 10);
            $table->string('name', 240)->nullable();
            $table->string('first_name', 30)->nullable();
            $table->string('last_name', 81)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('email', 255);
            $table->string('phone', 16)->nullable();
            $table->string('office', 4);
            $table->unsignedInteger('lock_version');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jpk_taxpayer_profiles');
    }
};
