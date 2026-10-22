<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $initial = DB::table('return_status_settings')->where('code', 'new')->value('id');
        // A worker loaded before deployment still omits the new local fields.
        Schema::table('order_returns', function (Blueprint $table) use ($initial): void {
            $table->string('source', 30)->default('allegro')->change();
            $table->string('creation_method', 30)->default('allegro_import')->change();
            $table->unsignedBigInteger('local_status_id')->nullable()->default($initial)->change();
        });
        DB::table('order_returns')->whereNotNull('external_return_id')->whereNull('request_key')
            ->where('creation_method', 'manual')->update(['source' => 'allegro', 'creation_method' => 'allegro_import']);
        DB::table('order_returns')->whereNotNull('external_return_id')->whereNull('local_status_id')
            ->update(['local_status_id' => $initial, 'local_status_changed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('order_returns', function (Blueprint $table): void {
            $table->string('source', 30)->default('manual')->change();
            $table->string('creation_method', 30)->default('manual')->change();
            $table->unsignedBigInteger('local_status_id')->nullable()->default(null)->change();
        });
    }
};
