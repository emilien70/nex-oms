<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'order_number')) {
            DB::table('orders')
                ->whereIn('source', ['allegro', 'prestashop'])
                ->where(function ($query): void {
                    $query->whereNull('external_id')->orWhere('external_id', '');
                })
                ->whereNotNull('order_number')
                ->where('order_number', '!=', '')
                ->orderBy('id')
                ->chunkById(100, function ($orders): void {
                    foreach ($orders as $order) {
                        DB::table('orders')
                            ->where('id', $order->id)
                            ->update(['external_id' => $order->order_number]);
                    }
                });

            Schema::table('orders', function (Blueprint $table): void {
                $table->dropColumn('order_number');
            });
        }

        $this->renameInPostContentSource('order_number', 'order_id');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'order_number')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->string('order_number')->nullable();
            });

            DB::table('orders')->orderBy('id')->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $number = in_array($order->source, ['allegro', 'prestashop'], true)
                        ? $order->external_id
                        : (string) $order->id;

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['order_number' => $number]);
                }
            });
        }

        $this->renameInPostContentSource('order_id', 'order_number');
    }

    private function renameInPostContentSource(string $from, string $to): void
    {
        if (! Schema::hasTable('courier_accounts')) {
            return;
        }

        DB::table('courier_accounts')
            ->where('provider', 'inpost_lockers')
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use ($from, $to): void {
                foreach ($accounts as $account) {
                    $settings = json_decode((string) $account->settings, true);

                    if (! is_array($settings) || ($settings['content_description_source'] ?? null) !== $from) {
                        continue;
                    }

                    $settings['content_description_source'] = $to;

                    DB::table('courier_accounts')
                        ->where('id', $account->id)
                        ->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
                }
            });
    }
};
