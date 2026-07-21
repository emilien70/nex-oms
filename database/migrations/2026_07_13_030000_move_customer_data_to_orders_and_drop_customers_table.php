<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'customer_login')) {
                $table->string('customer_login')->nullable()->after('notes');
            }

            if (! Schema::hasColumn('orders', 'customer_email')) {
                $table->string('customer_email')->nullable()->after('customer_login');
            }

            if (! Schema::hasColumn('orders', 'customer_phone')) {
                $table->string('customer_phone')->nullable()->after('customer_email');
            }
        });

        if (Schema::hasTable('customers') && Schema::hasColumn('orders', 'customer_id')) {
            DB::table('orders')
                ->leftJoin('customers', 'orders.customer_id', '=', 'customers.id')
                ->select([
                    'orders.id as order_id',
                    'customers.login',
                    'customers.email',
                    'customers.phone',
                ])
                ->orderBy('orders.id')
                ->get()
                ->each(function ($row): void {
                    DB::table('orders')
                        ->where('id', $row->order_id)
                        ->update([
                            'customer_login' => $row->login,
                            'customer_email' => $row->email,
                            'customer_phone' => $row->phone,
                        ]);
                });
        }

        $this->dropForeignIfPossible('addresses', 'customer_id');
        $this->dropForeignIfPossible('orders', 'customer_id');

        Schema::table('addresses', function (Blueprint $table): void {
            if (Schema::hasColumn('addresses', 'customer_id')) {
                $table->dropColumn('customer_id');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'customer_id')) {
                $table->dropColumn('customer_id');
            }
        });

        Schema::dropIfExists('customers');
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table): void {
                $table->id();
                $table->string('external_id')->nullable();
                $table->string('login')->nullable();
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('company_name')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('tax_id')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('status')->constrained()->nullOnDelete();
            }
        });

        Schema::table('addresses', function (Blueprint $table): void {
            if (! Schema::hasColumn('addresses', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('id')->constrained()->nullOnDelete();
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            foreach (['customer_login', 'customer_email', 'customer_phone'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function dropForeignIfPossible(string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            return;
        }

        try {
            Schema::table($tableName, function (Blueprint $table) use ($column): void {
                $table->dropForeign([$column]);
            });
        } catch (Throwable) {
            // SQLite rebuilds tables for dropColumn and cannot drop foreign keys separately.
        }
    }
};
