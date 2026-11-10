<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rule_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50)->unique();
            $table->unsignedInteger('sort_order');
            $table->timestamps();
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            // An inline reference avoids rebuilding the existing rules table on SQLite.
            DB::statement('ALTER TABLE automation_rules ADD COLUMN group_id INTEGER REFERENCES automation_rule_groups(id) ON DELETE SET NULL');
            Schema::table('automation_rules', function (Blueprint $table): void {
                $table->index('group_id');
            });

            return;
        }

        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->foreignId('group_id')->nullable()->index();
        });

        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->foreign('group_id')->references('id')->on('automation_rule_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || version_compare($connection->getServerVersion(), '3.35', '<')) {
            Schema::table('automation_rules', function (Blueprint $table): void {
                $table->dropForeign(['group_id']);
            });
        }

        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->dropIndex(['group_id']);
            $table->dropColumn('group_id');
        });

        Schema::dropIfExists('automation_rule_groups');
    }
};
