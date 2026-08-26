<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $addressColumns = [
        'name',
        'company_name',
        'tax_id',
        'street',
        'building_number',
        'apartment_number',
        'postal_code',
        'city',
        'province',
        'country_code',
        'phone',
        'email',
    ];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            foreach (['shipping', 'billing'] as $prefix) {
                foreach ($this->orderAddressColumns($prefix) as $column) {
                    $orderColumn = $prefix.'_'.$column;

                    if (! Schema::hasColumn('orders', $orderColumn)) {
                        $table->string($orderColumn)->nullable();
                    }
                }
            }
        });

        if (Schema::hasTable('addresses')) {
            DB::table('orders')
                ->leftJoin('addresses as shipping', 'orders.shipping_address_id', '=', 'shipping.id')
                ->leftJoin('addresses as billing', 'orders.billing_address_id', '=', 'billing.id')
                ->select([
                    'orders.id as order_id',
                    ...$this->selectAddressColumns('shipping'),
                    ...$this->selectAddressColumns('billing'),
                ])
                ->orderBy('orders.id')
                ->chunk(100, function ($orders): void {
                    foreach ($orders as $order) {
                        DB::table('orders')
                            ->where('id', $order->order_id)
                            ->update($this->addressUpdateData($order, 'shipping') + $this->addressUpdateData($order, 'billing'));
                    }
                });
        }

        $this->dropForeignIfPossible('orders', 'shipping_address_id');
        $this->dropForeignIfPossible('orders', 'billing_address_id');

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'shipping_address_id')) {
                $table->dropColumn('shipping_address_id');
            }

            if (Schema::hasColumn('orders', 'billing_address_id')) {
                $table->dropColumn('billing_address_id');
            }
        });

        Schema::dropIfExists('addresses');
    }

    public function down(): void
    {
        if (! Schema::hasTable('addresses')) {
            Schema::create('addresses', function (Blueprint $table): void {
                $table->id();
                $table->string('type');
                $table->string('name')->nullable();
                $table->string('company_name')->nullable();
                $table->string('tax_id')->nullable();
                $table->string('street')->nullable();
                $table->string('building_number')->nullable();
                $table->string('apartment_number')->nullable();
                $table->string('postal_code')->nullable();
                $table->string('city')->nullable();
                $table->string('province')->nullable();
                $table->string('country_code')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'shipping_address_id')) {
                $table->foreignId('shipping_address_id')->nullable()->after('customer_phone')->constrained('addresses')->nullOnDelete();
            }

            if (! Schema::hasColumn('orders', 'billing_address_id')) {
                $table->foreignId('billing_address_id')->nullable()->after('shipping_address_id')->constrained('addresses')->nullOnDelete();
            }
        });

        DB::table('orders')
            ->orderBy('id')
            ->chunk(100, function ($orders): void {
                foreach ($orders as $order) {
                    $shippingId = DB::table('addresses')->insertGetId($this->addressInsertData($order, 'shipping'));
                    $billingId = DB::table('addresses')->insertGetId($this->addressInsertData($order, 'billing'));

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update([
                            'shipping_address_id' => $shippingId,
                            'billing_address_id' => $billingId,
                        ]);
                }
            });

        Schema::table('orders', function (Blueprint $table): void {
            foreach (['shipping', 'billing'] as $prefix) {
                foreach ($this->orderAddressColumns($prefix) as $column) {
                    $orderColumn = $prefix.'_'.$column;

                    if (Schema::hasColumn('orders', $orderColumn)) {
                        $table->dropColumn($orderColumn);
                    }
                }
            }
        });
    }

    private function selectAddressColumns(string $prefix): array
    {
        return array_map(
            fn (string $column): string => $prefix.'.'.$column.' as '.$prefix.'_'.$column,
            $this->addressColumns
        );
    }

    private function addressUpdateData(object $order, string $prefix): array
    {
        $data = [];

        foreach ($this->addressColumns as $column) {
            if ($prefix === 'shipping' && $column === 'tax_id') {
                continue;
            }

            $data[$prefix.'_'.$column] = $order->{$prefix.'_'.$column} ?? null;
        }

        return $data;
    }

    private function addressInsertData(object $order, string $prefix): array
    {
        return [
            'type' => $prefix,
            'name' => $order->{$prefix.'_name'} ?? null,
            'company_name' => $order->{$prefix.'_company_name'} ?? null,
            'tax_id' => $order->{$prefix.'_tax_id'} ?? null,
            'street' => $order->{$prefix.'_street'} ?? null,
            'building_number' => $order->{$prefix.'_building_number'} ?? null,
            'apartment_number' => $order->{$prefix.'_apartment_number'} ?? null,
            'postal_code' => $order->{$prefix.'_postal_code'} ?? null,
            'city' => $order->{$prefix.'_city'} ?? null,
            'province' => $order->{$prefix.'_province'} ?? null,
            'country_code' => $order->{$prefix.'_country_code'} ?? null,
            'phone' => $order->{$prefix.'_phone'} ?? null,
            'email' => $order->{$prefix.'_email'} ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function dropForeignIfPossible(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $table) use ($column): void {
                $table->dropForeign([$column]);
            });
        } catch (Throwable) {
            //
        }
    }

    private function orderAddressColumns(string $prefix): array
    {
        return array_values(array_filter(
            $this->addressColumns,
            fn (string $column): bool => $prefix === 'billing' || $column !== 'tax_id'
        ));
    }
};
