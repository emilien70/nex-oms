<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ksef_latarnia_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('source_environment', 20);
            $table->string('external_message_id', 24);
            $table->unsignedBigInteger('event_id');
            $table->unsignedInteger('version');
            $table->string('category', 32);
            $table->string('type', 40);
            $table->string('title', 80);
            $table->text('text');
            $table->dateTimeTz('start_at');
            $table->dateTimeTz('end_at')->nullable();
            $table->dateTimeTz('published_at');
            $table->longText('payload_json');
            $table->string('payload_hash', 44);
            $table->dateTimeTz('first_fetched_at');
            $table->dateTimeTz('last_seen_at');
            $table->timestamps();

            $table->unique(
                ['source_environment', 'external_message_id', 'version'],
                'ksef_latarnia_message_version_unique',
            );
            $table->index(
                ['source_environment', 'event_id', 'category'],
                'ksef_latarnia_message_event_index',
            );
            $table->index(
                ['source_environment', 'start_at'],
                'ksef_latarnia_message_start_index',
            );
        });

        Schema::create('ksef_latarnia_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->string('source_environment', 20)->unique();
            $table->string('current_status', 32)->nullable();
            $table->longText('status_payload_json')->nullable();
            $table->string('status_payload_hash', 44)->nullable();
            $table->dateTimeTz('status_last_attempt_at')->nullable();
            $table->dateTimeTz('status_last_success_at')->nullable();
            $table->dateTimeTz('status_last_error_at')->nullable();
            $table->string('status_last_error_code', 100)->nullable();
            $table->string('status_last_error_message', 500)->nullable();
            $table->dateTimeTz('messages_last_attempt_at')->nullable();
            $table->dateTimeTz('messages_last_success_at')->nullable();
            $table->dateTimeTz('messages_last_error_at')->nullable();
            $table->string('messages_last_error_code', 100)->nullable();
            $table->string('messages_last_error_message', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ksef_latarnia_sync_states');
        Schema::dropIfExists('ksef_latarnia_messages');
    }
};
