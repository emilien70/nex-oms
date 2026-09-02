<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_offline_certificates', function (Blueprint $table): void {
            $table->string('remote_status', 50)->nullable();
            $table->string('remote_certificate_name', 120)->nullable();
            $table->dateTimeTz('remote_valid_from')->nullable();
            $table->dateTimeTz('remote_valid_until')->nullable();
            $table->dateTimeTz('remote_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ksef_offline_certificates', function (Blueprint $table): void {
            $table->dropColumn([
                'remote_status',
                'remote_certificate_name',
                'remote_valid_from',
                'remote_valid_until',
                'remote_verified_at',
            ]);
        });
    }
};
