<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'paid_amount')) {
                $table->decimal('paid_amount', 12, 2)->default(0)->after('total_gross');
            }

            if (! Schema::hasColumn('orders', 'star_color')) {
                $table->string('star_color', 20)->nullable()->after('status');
            }

            if (! Schema::hasColumn('orders', 'pickup_point_name')) {
                $table->string('pickup_point_name')->nullable()->after('shipping_method');
            }

            if (! Schema::hasColumn('orders', 'pickup_point_id')) {
                $table->string('pickup_point_id')->nullable()->after('pickup_point_name');
            }

            if (! Schema::hasColumn('orders', 'pickup_point_address')) {
                $table->string('pickup_point_address')->nullable()->after('pickup_point_id');
            }

            if (! Schema::hasColumn('orders', 'pickup_point_postal_code')) {
                $table->string('pickup_point_postal_code')->nullable()->after('pickup_point_address');
            }

            if (! Schema::hasColumn('orders', 'pickup_point_city')) {
                $table->string('pickup_point_city')->nullable()->after('pickup_point_postal_code');
            }
        });

        Schema::table('addresses', function (Blueprint $table) {
            if (! Schema::hasColumn('addresses', 'province')) {
                $table->string('province')->nullable()->after('city');
            }
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            if (Schema::hasColumn('addresses', 'province')) {
                $table->dropColumn('province');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            foreach ([
                'pickup_point_city',
                'pickup_point_postal_code',
                'pickup_point_address',
                'pickup_point_id',
                'pickup_point_name',
                'star_color',
                'paid_amount',
            ] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
