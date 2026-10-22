<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_status_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 100);
            $table->string('color', 7);
            $table->unsignedInteger('sort_order');
            $table->timestamps();
        });
        foreach ([['new', 'Nowe', '#9ca3af'], ['received', 'Odebrane', '#bdddff'], ['in_progress', 'W trakcie', '#007bdf'], ['completed', 'Zakończone', '#16834b'], ['rejected', 'Odrzucone', '#ffad42']] as $position => [$code, $name, $color]) {
            DB::table('return_status_settings')->insert(['code' => $code, 'name' => $name, 'color' => $color, 'sort_order' => $position, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('order_returns', function (Blueprint $table): void {
            $table->string('source', 30)->default('manual')->change();
            $table->string('environment', 20)->nullable()->change();
            $table->string('seller_user_id', 100)->nullable()->change();
            $table->uuid('external_return_id')->nullable()->change();
            $table->uuid('external_order_id')->nullable()->change();
            $table->string('reference_number')->nullable()->change();
            $table->string('account_name')->nullable()->change();
            $table->string('status', 100)->nullable()->change();
            $table->string('source_hash', 64)->nullable()->change();
            $table->timestamp('status_observed_at')->nullable()->change();
            $table->timestamp('synced_at')->nullable()->change();
            $table->timestamp('next_sync_at')->nullable()->change();
            $table->string('creation_method', 30)->default('manual');
            $table->foreignId('local_status_id')->nullable()->constrained('return_status_settings')->nullOnDelete();
            $table->timestamp('local_status_changed_at')->nullable();
            $table->string('star_color', 20)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->uuid('request_key')->nullable()->unique();
            $table->string('creation_fingerprint', 64)->nullable();
        });
        Schema::table('order_return_items', fn (Blueprint $table) => $table->string('offer_id', 100)->nullable()->change());
        $initial = DB::table('return_status_settings')->where('code', 'new')->value('id');
        DB::table('order_returns')->update(['creation_method' => 'allegro_import', 'local_status_id' => $initial, 'local_status_changed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Removing generalized data is intentionally not automatic: manual returns cannot become Allegro records.
        throw new RuntimeException('Migracja zawiera zwroty ogólne. Przywrócenie wymaga osobnej, bezpiecznej migracji danych.');
    }
};
