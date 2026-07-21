<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('reference_number', 100)->nullable()->after('content_description');
            $table->boolean('swap_sender_receiver')->default(false)->after('reference_number');
        });

        Schema::table('shipment_parcels', function (Blueprint $table): void {
            $table->string('package_type', 30)->default('PACKAGE')->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_parcels', function (Blueprint $table): void {
            $table->dropColumn('package_type');
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn(['reference_number', 'swap_sender_receiver']);
        });
    }
};
