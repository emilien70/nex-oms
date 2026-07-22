<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_series', function (Blueprint $table): void {
            $table->boolean('is_system')->default(false)->after('is_active');
            $table->string('system_key', 30)->nullable()->after('is_system');
            $table->unique('system_key', 'invoice_series_system_key_unique');
            $table->index(
                ['document_type', 'is_system'],
                'invoice_series_document_type_is_system_index'
            );
        });

        $correctionSeriesId = $this->upsertSystemSeries([
            'document_type' => 'correction',
            'name' => 'Korekty',
            'number_format' => 'BLK %N/%Y',
            'reset_period' => 'yearly',
            'fiscal_year_start_month' => 1,
            'is_system' => true,
            'system_key' => 'correction',
            'is_active' => true,
            'default_correction_series_id' => null,
            'default_currency' => 'PLN',
        ]);

        $this->upsertSystemSeries([
            'document_type' => 'invoice',
            'name' => 'Faktury',
            'number_format' => 'BL %N/%Y',
            'reset_period' => 'yearly',
            'fiscal_year_start_month' => 1,
            'is_system' => true,
            'system_key' => 'invoice',
            'is_active' => true,
            'default_correction_series_id' => $correctionSeriesId,
            'default_currency' => 'PLN',
        ]);

        $this->upsertSystemSeries([
            'document_type' => 'proforma',
            'name' => 'Faktury Pro-Forma',
            'number_format' => 'BLPF %N/%Y',
            'reset_period' => 'yearly',
            'fiscal_year_start_month' => 1,
            'is_system' => true,
            'system_key' => 'proforma',
            'is_active' => true,
            'default_correction_series_id' => null,
            'default_currency' => 'PLN',
        ]);

        Schema::table('invoice_series', function (Blueprint $table): void {
            $table->dropIndex('invoice_series_document_type_is_default_index');
        });

        Schema::table('invoice_series', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_series', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('fiscal_year_start_month');
        });

        DB::table('invoice_series')
            ->where('is_system', true)
            ->whereIn('system_key', ['invoice', 'correction', 'proforma'])
            ->update(['is_default' => true]);

        Schema::table('invoice_series', function (Blueprint $table): void {
            $table->index(
                ['document_type', 'is_default'],
                'invoice_series_document_type_is_default_index'
            );
            $table->dropUnique('invoice_series_system_key_unique');
            $table->dropIndex('invoice_series_document_type_is_system_index');
        });

        Schema::table('invoice_series', function (Blueprint $table): void {
            $table->dropColumn(['system_key', 'is_system']);
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function upsertSystemSeries(array $values): int
    {
        $existing = DB::table('invoice_series')
            ->where('document_type', $values['document_type'])
            ->where('name', $values['name'])
            ->first();

        $values['updated_at'] = now();

        if ($existing !== null) {
            DB::table('invoice_series')->where('id', $existing->id)->update($values);

            return (int) $existing->id;
        }

        $values['created_at'] = now();

        return (int) DB::table('invoice_series')->insertGetId($values);
    }
};
