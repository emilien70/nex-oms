<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->string('correction_sync_mode', 20)->default('disabled');
            $table->foreignId('correction_sync_status_setting_id')->nullable()->constrained('order_status_settings')->nullOnDelete();
            $table->string('correction_sync_identity')->nullable();
            $table->unsignedBigInteger('correction_sync_revision')->default(0);
        });
        Schema::table('allegro_invoice_exports', function (Blueprint $table): void {
            $table->string('document_type', 20)->default('invoice');
            $table->string('source_invoice_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('allegro_invoice_exports', function (Blueprint $table): void {
            $table->dropColumn(['document_type', 'source_invoice_number']);
        });
        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('correction_sync_status_setting_id');
            $table->dropColumn(['correction_sync_mode', 'correction_sync_identity', 'correction_sync_revision']);
        });
    }
};
