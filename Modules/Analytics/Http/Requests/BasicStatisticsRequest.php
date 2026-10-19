<?php

namespace Modules\Analytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BasicStatisticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['orders', 'sources', 'fulfillment', 'customers', 'products'])],
            'range' => ['nullable', Rule::in([
                'today', 'since_yesterday', 'last_7_days', 'last_14_days', 'last_28_days', 'last_30_days',
                'current_month', 'previous_month', 'current_quarter', 'previous_quarter',
                'current_year', 'previous_year', 'custom',
            ])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'interval' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'sources_filter' => ['nullable', 'boolean'],
            'sources' => ['nullable', 'array', 'max:100'],
            'sources.*' => ['string', 'max:100', 'distinct'],
            'statuses_filter' => ['nullable', 'boolean'],
            'statuses' => ['nullable', 'array', 'max:100'],
            'statuses.*' => ['string', 'max:100', 'distinct'],
            'section' => ['nullable', 'string', 'max:100'],
        ];
    }
}
