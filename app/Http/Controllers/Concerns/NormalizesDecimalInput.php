<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait NormalizesDecimalInput
{
    protected function normalizeDecimalFields(Request $request, array $fields): void
    {
        $normalized = [];

        foreach ($fields as $field) {
            if ($request->exists($field)) {
                $normalized[$field] = $this->normalizeDecimalValue($request->input($field));
            }
        }

        if ($normalized !== []) {
            $request->merge($normalized);
        }
    }

    protected function normalizeNestedDecimalFields(Request $request, string $group, array $fields): void
    {
        $items = $request->input($group);

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach ($fields as $field) {
                if (array_key_exists($field, $item)) {
                    $items[$index][$field] = $this->normalizeDecimalValue($item[$field]);
                }
            }
        }

        $request->merge([$group => $items]);
    }

    private function normalizeDecimalValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = preg_replace('/[\s\x{00A0}]+/u', '', trim($value)) ?? trim($value);

        return str_replace(',', '.', $value);
    }
}
