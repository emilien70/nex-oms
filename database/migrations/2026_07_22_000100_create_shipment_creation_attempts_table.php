<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_creation_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->index();
            $table->uuid('request_uuid')->unique();
            $table->string('status')->default('queued')->index();
            $table->json('request_data')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('outcome_unknown')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->foreignId('creation_attempt_id')
                ->nullable()
                ->unique()
                ->after('courier_account_id')
                ->constrained('shipment_creation_attempts')
                ->nullOnDelete();
        });

        Schema::table('integration_api_logs', function (Blueprint $table): void {
            $table->foreignId('shipment_creation_attempt_id')
                ->nullable()
                ->after('shipment_id')
                ->constrained('shipment_creation_attempts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('integration_api_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shipment_creation_attempt_id');
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('creation_attempt_id');
        });

        Schema::dropIfExists('shipment_creation_attempts');
    }
};
