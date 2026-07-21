<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('dropoff_point_id')->nullable()->after('target_point_id');
            $table->string('content_description', 100)->nullable()->after('sending_method');
            $table->timestamp('status_changed_at')->nullable()->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex(['status_changed_at']);
            $table->dropColumn(['dropoff_point_id', 'content_description', 'status_changed_at']);
        });
    }
};
