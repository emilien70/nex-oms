<?php

namespace Modules\Invoices\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;
use Modules\Invoices\Models\InvoiceSeries;

class UpdateInvoiceSeriesRequest extends InvoiceSeriesRequest
{
    private const NUMBERING_IDENTITY_FIELDS = [
        'document_type',
        'number_format',
        'reset_period',
        'fiscal_year_start_month',
    ];

    private const NUMBERING_IDENTITY_LOCK_MESSAGE = 'Nie można zmienić parametrów numeracji, ponieważ seria została już użyta do numerowania dokumentów.';

    protected function prepareForValidation(): void
    {
        $series = $this->series();

        if ($series->is_system) {
            $this->merge([
                'document_type' => $series->document_type->value,
                'is_active' => true,
            ]);
        }

        parent::prepareForValidation();
    }

    protected function uniqueNameRule(): Unique
    {
        return Rule::unique('invoice_series', 'name')
            ->where('document_type', (string) $this->input('document_type'))
            ->ignore($this->series()->getKey());
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            $series = $this->series();

            if (! $series->numberingHasStarted()) {
                return;
            }

            foreach (self::NUMBERING_IDENTITY_FIELDS as $field) {
                if (! $this->exists($field)) {
                    continue;
                }

                $current = $series->{$field};
                if ($current instanceof \BackedEnum) {
                    $current = $current->value;
                }

                if ((string) $current !== (string) $this->input($field)) {
                    $validator->errors()->add('numbering_identity', self::NUMBERING_IDENTITY_LOCK_MESSAGE);

                    return;
                }
            }
        }];
    }

    private function series(): InvoiceSeries
    {
        $series = $this->route('series');

        abort_unless($series instanceof InvoiceSeries, 404);

        return $series;
    }
}
