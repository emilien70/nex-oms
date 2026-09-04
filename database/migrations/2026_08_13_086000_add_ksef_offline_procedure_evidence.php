<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_offline_issuances', function (Blueprint $table): void {
            $table->string('latarnia_source_environment', 20)->nullable();
            $table->unsignedBigInteger('latarnia_trigger_event_id')->nullable();
            $table->string('latarnia_trigger_message_id', 24)->nullable();
            $table->unsignedInteger('latarnia_trigger_message_version')->nullable();
            $table->string('latarnia_trigger_category', 32)->nullable();
            $table->dateTimeTz('latarnia_trigger_start_at')->nullable();
            $table->dateTimeTz('latarnia_trigger_end_at')->nullable();
            $table->dateTimeTz('latarnia_trigger_published_at')->nullable();
            $table->dateTimeTz('latarnia_evidence_as_of_at')->nullable();
            $table->dateTimeTz('latarnia_evidence_from_at')->nullable();
            $table->dateTimeTz('latarnia_evidence_through_at')->nullable();

            $table->index(
                ['latarnia_source_environment', 'latarnia_trigger_event_id'],
                'ksef_offline_issuance_latarnia_event_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('ksef_offline_issuances', function (Blueprint $table): void {
            $table->dropIndex('ksef_offline_issuance_latarnia_event_index');
            $table->dropColumn([
                'latarnia_source_environment',
                'latarnia_trigger_event_id',
                'latarnia_trigger_message_id',
                'latarnia_trigger_message_version',
                'latarnia_trigger_category',
                'latarnia_trigger_start_at',
                'latarnia_trigger_end_at',
                'latarnia_trigger_published_at',
                'latarnia_evidence_as_of_at',
                'latarnia_evidence_from_at',
                'latarnia_evidence_through_at',
            ]);
        });
    }
};
