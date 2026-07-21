<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->index();
            $table->string('external_id')->nullable();
            $table->string('tracking_number')->nullable()->index();
            $table->string('service');
            $table->string('parcel_template')->nullable();
            $table->string('status')->default('queued')->index();
            $table->string('target_point_id')->nullable();
            $table->string('sending_method')->default('dispatch_order');
            $table->decimal('cod_amount', 12, 2)->nullable();
            $table->decimal('insurance_amount', 12, 2)->nullable();
            $table->string('currency', 10)->default('PLN');
            $table->string('label_format', 10)->default('Pdf');
            $table->string('label_type', 10)->default('A6');
            $table->uuid('request_uuid')->unique();
            $table->text('error_message')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
