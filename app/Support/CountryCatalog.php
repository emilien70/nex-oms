<?php

namespace App\Support;

use Symfony\Component\Intl\Countries;

class CountryCatalog
{
    /** @return array<string, string> */
    public function all(): array
    {
        $countries = [];

        foreach (Countries::getCountryCodes() as $code) {
            $countries[$code] = Countries::getName($code, 'pl');
        }

        uksort($countries, static function (string $leftCode, string $rightCode) use ($countries): int {
            $nameComparison = strnatcasecmp($countries[$leftCode], $countries[$rightCode]);

            return $nameComparison !== 0 ? $nameComparison : strcmp($leftCode, $rightCode);
        });

        $poland = $countries['PL'];

        unset($countries['PL']);

        return ['PL' => $poland] + $countries;
    }

    public function name(?string $code): ?string
    {
        $normalized = $this->normalize($code);

        return $normalized !== null && Countries::exists($normalized)
            ? Countries::getName($normalized, 'pl')
            : null;
    }

    public function exists(?string $code): bool
    {
        $normalized = $this->normalize($code);

        return $normalized !== null && Countries::exists($normalized);
    }

    public function normalize(?string $code): ?string
    {
        $normalized = strtoupper(trim((string) $code));

        return $normalized !== '' ? $normalized : null;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->all());
    }
}
