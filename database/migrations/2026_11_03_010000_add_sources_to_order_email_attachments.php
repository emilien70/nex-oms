<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_email_message_attachments', function (Blueprint $table): void {
            $table->string('source_mode', 24)->nullable();
            $table->string('file_path')->nullable()->change();
            $table->unsignedBigInteger('file_size')->nullable()->change();
            $table->char('checksum_sha256', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('order_email_message_attachments')
            ->whereNotNull('source_mode')
            ->orWhereNull('file_path')
            ->orWhereNull('file_size')
            ->orWhereNull('checksum_sha256')
            ->exists()) {
            throw new RuntimeException('Cannot remove email attachment sources while reference attachments exist.');
        }

        Schema::table('order_email_message_attachments', function (Blueprint $table): void {
            $table->string('file_path')->nullable(false)->change();
            $table->unsignedBigInteger('file_size')->nullable(false)->change();
            $table->char('checksum_sha256', 64)->nullable(false)->change();
            $table->dropColumn('source_mode');
        });
    }
};
