<?php

namespace Modules\Integrations\Prestashop\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePrestashopAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'base_url' => [
                'required',
                'string',
                'max:2048',
                'url:https',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $parts = parse_url((string) $value);
                    $host = strtolower((string) ($parts['host'] ?? ''));

                    if (
                        $host === ''
                        || isset($parts['user'])
                        || isset($parts['pass'])
                        || isset($parts['query'])
                        || isset($parts['fragment'])
                    ) {
                        $fail('Podaj bazowy adres HTTPS sklepu bez loginu, parametrów i fragmentu.');

                        return;
                    }

                    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
                        $fail('Adres sklepu nie może wskazywać na localhost.');

                        return;
                    }

                    if (
                        filter_var($host, FILTER_VALIDATE_IP)
                        && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                    ) {
                        $fail('Adres sklepu nie może wskazywać na prywatny lub zastrzeżony adres IP.');
                    }
                },
            ],
            'product_url_template' => [
                'required',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $template = (string) $value;

                    if (! str_contains($template, '[product_id]')) {
                        $fail('Wzór linku do produktu musi zawierać znacznik [product_id].');

                        return;
                    }

                    $url = str_replace('[product_id]', '1', $template);
                    $parts = parse_url($url);
                    $baseParts = parse_url((string) $this->input('base_url'));

                    if (
                        filter_var($url, FILTER_VALIDATE_URL) === false
                        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                        || isset($parts['user'])
                        || isset($parts['pass'])
                        || isset($parts['fragment'])
                    ) {
                        $fail('Podaj prawidłowy wzór adresu HTTPS produktu bez loginu i fragmentu.');

                        return;
                    }

                    if (strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($baseParts['host'] ?? ''))) {
                        $fail('Wzór linku do produktu musi wskazywać domenę skonfigurowanego sklepu.');
                    }
                },
            ],
            'api_key' => ['nullable', 'string', 'size:32'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'base_url.url' => 'Adres sklepu musi być prawidłowym adresem HTTPS.',
            'api_key.size' => 'Klucz API PrestaShop musi mieć dokładnie 32 znaki.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'base_url' => trim((string) $this->input('base_url', '')),
            'product_url_template' => trim((string) $this->input('product_url_template', '')),
            'api_key' => trim((string) $this->input('api_key', '')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
