<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_portal_settings', function (Blueprint $table): void {
            $table->string('customer_domain', 253)->nullable();
            $table->boolean('mask_personal_data')->default(true);
            $table->boolean('verify_documents')->default(true);
            $table->string('verification_method', 20)->default('any');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Customer portal privacy settings must not be removed by an automatic rollback.');
    }
};
