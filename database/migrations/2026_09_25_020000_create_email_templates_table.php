<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_account_id')
                ->nullable()
                ->constrained('email_accounts')
                ->nullOnDelete();
            $table->string('name', 160);
            $table->string('subject_template');
            $table->string('format', 20)->default('plain_text');
            $table->text('body_template');
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();

            $table->index(['is_hidden', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
