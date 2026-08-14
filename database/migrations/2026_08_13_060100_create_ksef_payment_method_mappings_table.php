<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ksef_payment_method_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key')->unique();
            $table->string('source_label');
            $table->string('target_type', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ksef_payment_method_mappings');
    }
};
