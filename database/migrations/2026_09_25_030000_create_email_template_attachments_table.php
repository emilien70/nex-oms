<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_template_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_template_id')
                ->constrained('email_templates')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->string('type', 20);
            $table->string('document_type', 40)->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();

            $table->unique(['email_template_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_attachments');
    }
};
