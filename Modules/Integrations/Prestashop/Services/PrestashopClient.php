<?php

namespace Modules\Integrations\Prestashop\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use SimpleXMLElement;
use Throwable;

class PrestashopClient
{
    private const REQUIRED_RESOURCES = [
        'shops',
        'languages',
        'currencies',
        'order_states',
        'orders',
        'order_details',
        'addresses',
        'customers',
        'carriers',
        'countries',
        'states',
        'order_payments',
    ];

    private const IMPORT_RESOURCES = [
        'orders',
        'order_details',
        'addresses',
        'customers',
        'carriers',
        'countries',
        'states',
        'order_payments',
    ];

    private const ORDER_ENRICHMENT_RESOURCES = [
        'bl_order',
        'base_order',
    ];

    /**
     * @return array{
     *     shop: array{id: int, name: string},
     *     language: array{id: int, name: string, iso_code: string},
     *     languages: array<int, array{id: int, name: string, iso_code: string}>,
     *     currencies: array<int, array{id: int, name: string, iso_code: string}>,
     *     resources: array<int, string>,
     *     version: ?string,
     *     order_enrichment_resource: ?string,
     *     order_states: array<int, array<string, int|string|bool|null>>
     * }
     */
    public function discover(PrestashopAccount $account): array
    {
        if (! $account->hasCompleteCredentials()) {
            throw new PrestashopApiException('Najpierw zapisz adres sklepu i Klucz API PrestaShop.');
        }

        $shopsResponse = $this->getJson($account, 'shops', [
            'display' => '[id,name,active]',
        ]);
        $shops = $this->normalizeShops(
            $this->rows(
                $this->payload($shopsResponse),
                'shops',
                'shop',
            ),
        );
        $activeShops = array_values(array_filter($shops, fn (array $shop): bool => $shop['active']));

        if (count($activeShops) !== 1) {
            throw new PrestashopApiException(
                count($activeShops) > 1
                    ? 'Wykryto więcej niż jeden aktywny sklep. Ta konfiguracja obsługuje pojedynczy sklep PrestaShop.'
                    : 'Nie wykryto jednego aktywnego sklepu PrestaShop.',
            );
        }

        $languages = $this->normalizeLanguages(
            $this->rows(
                $this->payload($this->getJson($account, 'languages', [
                    'display' => '[id,name,iso_code,active]',
                ])),
                'languages',
                'language',
            ),
        );
        $activeLanguages = array_values(array_filter($languages, fn (array $language): bool => $language['active']));

        if ($activeLanguages === []) {
            throw new PrestashopApiException('Nie wykryto aktywnego języka sklepu PrestaShop.');
        }

        $language = collect($activeLanguages)->firstWhere('iso_code', 'pl') ?? $activeLanguages[0];
        $currencies = $this->normalizeCurrencies(
            $this->rows(
                $this->payload($this->getJson($account, 'currencies', [
                    'display' => '[id,name,iso_code,active,deleted]',
                ])),
                'currencies',
                'currency',
            ),
        );
        $activeCurrencies = array_values(array_filter(
            $currencies,
            fn (array $currency): bool => $currency['active'] && ! $currency['deleted'],
        ));

        if ($activeCurrencies === []) {
            throw new PrestashopApiException('Nie wykryto aktywnej waluty sklepu PrestaShop.');
        }

        $orderStates = $this->normalizeOrderStates(
            $this->xmlRows(
                $this->getXml($account, 'order_states', [
                    'display' => '[id,name,color,unremovable,delivery,hidden,send_email,invoice,logable,shipped,paid,deleted]',
                    'language' => $language['id'],
                ]),
                'order_states',
                'order_state',
            ),
        );

        if ($orderStates === []) {
            throw new PrestashopApiException('PrestaShop nie zwrócił żadnych statusów zamówień.');
        }

        foreach (self::IMPORT_RESOURCES as $resource) {
            $this->getJson($account, $resource, [
                'display' => '[id]',
                'limit' => 1,
            ]);
        }

        $orderEnrichmentResource = $this->discoverOrderEnrichmentResource($account);

        $shop = $activeShops[0];

        return [
            'shop' => [
                'id' => $shop['id'],
                'name' => $shop['name'],
            ],
            'language' => [
                'id' => $language['id'],
                'name' => $language['name'],
                'iso_code' => $language['iso_code'],
            ],
            'languages' => array_map(
                fn (array $item): array => [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'iso_code' => $item['iso_code'],
                ],
                $activeLanguages,
            ),
            'currencies' => array_map(
                fn (array $item): array => [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'iso_code' => $item['iso_code'],
                ],
                $activeCurrencies,
            ),
            'resources' => self::REQUIRED_RESOURCES,
            'order_enrichment_resource' => $orderEnrichmentResource,
            'version' => $this->version($shopsResponse),
            'order_states' => $orderStates,
        ];
    }

    /** @return array<string, mixed> */
    public function findOrder(PrestashopAccount $account, string $identifier): array
    {
        $identifier = trim($identifier);

        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $identifier) !== 1) {
            throw new PrestashopApiException('Podaj prawidłowe ID albo numer referencyjny zamówienia PrestaShop.');
        }

        if (ctype_digit($identifier) && (int) $identifier > 0) {
            try {
                return $this->record(
                    $this->payload($this->getJson($account, 'orders/'.$identifier, ['display' => 'full'])),
                    'orders',
                    'order',
                );
            } catch (PrestashopApiException $exception) {
                if ($exception->statusCode !== 404) {
                    throw $exception;
                }
            }
        }

        $orders = $this->rows(
            $this->payload($this->getJson($account, 'orders', [
                'filter[reference]' => '['.$identifier.']',
                'display' => 'full',
                'limit' => 2,
            ])),
            'orders',
            'order',
        );

        if (count($orders) !== 1) {
            throw new PrestashopApiException(
                count($orders) > 1
                    ? 'Numer referencyjny wskazuje więcej niż jedno zamówienie PrestaShop.'
                    : 'Nie znaleziono zamówienia PrestaShop o podanym ID lub numerze referencyjnym.',
            );
        }

        return $orders[0];
    }

    /** @return array<string, mixed> */
    public function orderEnrichment(PrestashopAccount $account, int $orderId): array
    {
        $resource = trim((string) $account->order_enrichment_resource);

        if ($orderId < 1 || ! in_array($resource, self::ORDER_ENRICHMENT_RESOURCES, true)) {
            return [];
        }

        try {
            $payload = $this->payload($this->getJson($account, $resource.'/'.$orderId, [
                'display' => 'full',
            ]));
        } catch (PrestashopApiException) {
            return [];
        }

        foreach (['order', 'bl_order', 'base_order'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        foreach (['orders', 'bl_orders', 'base_orders'] as $key) {
            $rows = $payload[$key] ?? null;

            if (! is_array($rows) || $rows === []) {
                continue;
            }

            if (array_is_list($rows)) {
                return is_array($rows[0] ?? null) ? $rows[0] : [];
            }

            return $rows;
        }

        return [];
    }

    /** @return array<int, array<string, mixed>> */
    public function orderDetails(PrestashopAccount $account, int $orderId): array
    {
        return $this->rows(
            $this->payload($this->getJson($account, 'order_details', [
                'filter[id_order]' => '['.$orderId.']',
                'display' => 'full',
            ])),
            'order_details',
            'order_detail',
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function orderPayments(PrestashopAccount $account, string $reference): array
    {
        return $this->rows(
            $this->payload($this->getJson($account, 'order_payments', [
                'filter[order_reference]' => '['.$reference.']',
                'display' => 'full',
            ])),
            'order_payments',
            'order_payment',
        );
    }

    /** @return array<string, mixed> */
    public function address(PrestashopAccount $account, int $id): array
    {
        return $this->recordResource($account, 'addresses', 'address', $id);
    }

    /** @return array<string, mixed> */
    public function customer(PrestashopAccount $account, int $id): array
    {
        return $this->recordResource($account, 'customers', 'customer', $id);
    }

    /** @return array<string, mixed> */
    public function carrier(PrestashopAccount $account, int $id): array
    {
        return $this->recordResource($account, 'carriers', 'carrier', $id);
    }

    /** @return array<string, mixed> */
    public function currency(PrestashopAccount $account, int $id): array
    {
        return $this->recordResource($account, 'currencies', 'currency', $id);
    }

    /** @return array<string, mixed> */
    public function country(PrestashopAccount $account, int $id): array
    {
        return $this->recordResource($account, 'countries', 'country', $id);
    }

    /** @return array<string, mixed> */
    public function state(PrestashopAccount $account, int $id): array
    {
        return $this->recordResource($account, 'states', 'state', $id);
    }

    /** @return array<string, mixed> */
    private function recordResource(
        PrestashopAccount $account,
        string $collection,
        string $singular,
        int $id,
    ): array {
        if ($id < 1) {
            throw new PrestashopApiException("PrestaShop zwrócił nieprawidłowe ID zasobu: {$collection}.");
        }

        return $this->record(
            $this->payload($this->getJson($account, $collection.'/'.$id, ['display' => 'full'])),
            $collection,
            $singular,
        );
    }

    private function getJson(PrestashopAccount $account, string $resource, array $query = []): Response
    {
        return $this->get($account, $resource, $query, 'JSON', 'application/json');
    }

    private function discoverOrderEnrichmentResource(PrestashopAccount $account): ?string
    {
        foreach (self::ORDER_ENRICHMENT_RESOURCES as $resource) {
            try {
                $this->getJson($account, $resource, [
                    'display' => '[id]',
                    'limit' => 1,
                ]);

                return $resource;
            } catch (PrestashopApiException) {
                continue;
            }
        }

        return null;
    }

    private function getXml(PrestashopAccount $account, string $resource, array $query = []): Response
    {
        return $this->get($account, $resource, $query, 'XML', 'application/xml');
    }

    private function get(
        PrestashopAccount $account,
        string $resource,
        array $query,
        string $format,
        string $accept,
    ): Response {
        $url = rtrim((string) $account->base_url, '/').'/api/';

        if ($resource !== '') {
            $url .= implode('/', array_map('rawurlencode', explode('/', trim($resource, '/'))));
        }

        try {
            $response = Http::withHeaders([
                'Accept' => $accept,
                'Output-Format' => $format,
                'User-Agent' => 'NEX-OMS PrestaShop Integration',
            ])
                ->connectTimeout(5)
                ->timeout(15)
                ->withBasicAuth((string) $account->api_key, '')
                ->withOptions(['allow_redirects' => false])
                ->get($url, array_merge(['output_format' => $format], $query));
        } catch (ConnectionException) {
            throw new PrestashopApiException(
                'Nie udało się połączyć ze sklepem PrestaShop. Sprawdź adres i połączenie sieciowe.',
            );
        } catch (Throwable) {
            throw new PrestashopApiException('Wystąpił nieoczekiwany błąd komunikacji z PrestaShop.');
        }

        if (! $response->successful()) {
            throw new PrestashopApiException(
                $this->safeErrorMessage($response, $resource),
                $response->status(),
            );
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Response $response): array
    {
        try {
            $payload = $response->json();
        } catch (Throwable) {
            $payload = null;
        }

        if (! is_array($payload)) {
            throw new PrestashopApiException('PrestaShop zwrócił odpowiedź w nieobsługiwanym formacie.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $payload, string $collection, string $singular): array
    {
        $data = $payload[$collection] ?? data_get($payload, 'prestashop.'.$collection, []);

        if (is_array($data) && array_key_exists($singular, $data)) {
            $data = $data[$singular];
        }

        if (! is_array($data) || $data === []) {
            return [];
        }

        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        return [is_array($data) ? $data : []];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function record(array $payload, string $collection, string $singular): array
    {
        $direct = $payload[$singular] ?? data_get($payload, 'prestashop.'.$singular);

        if (is_array($direct) && $direct !== []) {
            return $direct;
        }

        $rows = $this->rows($payload, $collection, $singular);

        if ($rows === []) {
            throw new PrestashopApiException("PrestaShop nie zwrócił danych zasobu: {$collection}.");
        }

        return $rows[0];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function xmlRows(Response $response, string $collection, string $singular): array
    {
        try {
            $xml = new SimpleXMLElement($response->body(), LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
        } catch (Throwable) {
            throw new PrestashopApiException('PrestaShop zwrócił odpowiedź XML w nieobsługiwanym formacie.');
        }

        if (! isset($xml->{$collection})) {
            return [];
        }

        $rows = [];

        foreach ($xml->{$collection}->{$singular} as $node) {
            $row = [];

            foreach ($node->children() as $field => $value) {
                $row[$field] = $this->xmlValue($value);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function xmlValue(SimpleXMLElement $node): string
    {
        $value = trim((string) $node);

        if ($value !== '') {
            return $value;
        }

        foreach ($node->children() as $child) {
            $value = $this->xmlValue($child);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{id: int, name: string, active: bool}>
     */
    private function normalizeShops(array $rows): array
    {
        return $this->normalizeRows($rows, function (array $row): ?array {
            $id = $this->positiveId($row['id'] ?? null);
            $name = $this->localizedValue($row['name'] ?? '');

            return $id && $name !== ''
                ? ['id' => $id, 'name' => $name, 'active' => $this->toBool($row['active'] ?? false)]
                : null;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{id: int, name: string, iso_code: string, active: bool}>
     */
    private function normalizeLanguages(array $rows): array
    {
        return $this->normalizeRows($rows, function (array $row): ?array {
            $id = $this->positiveId($row['id'] ?? null);
            $name = $this->localizedValue($row['name'] ?? '');
            $isoCode = strtolower(trim((string) ($row['iso_code'] ?? '')));

            return $id && $name !== '' && preg_match('/^[a-z]{2,10}$/', $isoCode) === 1
                ? ['id' => $id, 'name' => $name, 'iso_code' => $isoCode, 'active' => $this->toBool($row['active'] ?? false)]
                : null;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{id: int, name: string, iso_code: string, active: bool, deleted: bool}>
     */
    private function normalizeCurrencies(array $rows): array
    {
        return $this->normalizeRows($rows, function (array $row): ?array {
            $id = $this->positiveId($row['id'] ?? null);
            $name = $this->localizedValue($row['name'] ?? '');
            $isoCode = strtoupper(trim((string) ($row['iso_code'] ?? '')));

            return $id && $name !== '' && preg_match('/^[A-Z]{3}$/', $isoCode) === 1
                ? [
                    'id' => $id,
                    'name' => $name,
                    'iso_code' => $isoCode,
                    'active' => $this->toBool($row['active'] ?? false),
                    'deleted' => $this->toBool($row['deleted'] ?? false),
                ]
                : null;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, int|string|bool|null>>
     */
    private function normalizeOrderStates(array $rows): array
    {
        return $this->normalizeRows($rows, function (array $row): ?array {
            $id = $this->positiveId($row['id'] ?? null);
            $name = $this->localizedValue($row['name'] ?? '');
            $color = trim((string) ($row['color'] ?? ''));
            $color = preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? $color : null;

            if (! $id || $name === '') {
                return null;
            }

            return [
                'external_id' => $id,
                'name' => mb_substr($name, 0, 255),
                'color' => $color,
                'unremovable' => $this->toBool($row['unremovable'] ?? false),
                'delivery' => $this->toBool($row['delivery'] ?? false),
                'hidden' => $this->toBool($row['hidden'] ?? false),
                'send_email' => $this->toBool($row['send_email'] ?? false),
                'invoice' => $this->toBool($row['invoice'] ?? false),
                'logable' => $this->toBool($row['logable'] ?? false),
                'shipped' => $this->toBool($row['shipped'] ?? false),
                'paid' => $this->toBool($row['paid'] ?? false),
                'deleted' => $this->toBool($row['deleted'] ?? false),
            ];
        });
    }

    /**
     * @template T of array
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): ?T  $normalizer
     * @return array<int, T>
     */
    private function normalizeRows(array $rows, callable $normalizer): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $item = $normalizer($row);

            if ($item !== null) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    private function localizedValue(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        if (! is_array($value)) {
            return '';
        }

        if (array_key_exists('language', $value)) {
            return $this->localizedValue($value['language']);
        }

        if (array_key_exists('value', $value)) {
            return $this->localizedValue($value['value']);
        }

        foreach ($value as $item) {
            $normalized = $this->localizedValue($item);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }

    private function toBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'TRUE'], true);
    }

    private function version(Response $response): ?string
    {
        $version = trim(
            $response->header('PSWS-Version')
                ?: $response->header('PrestaShop-Version')
                ?: '',
        );

        return $version !== '' ? mb_substr($version, 0, 40) : null;
    }

    private function safeErrorMessage(Response $response, string $resource): string
    {
        if ($response->redirect()) {
            return 'Adres sklepu przekierowuje żądania API. Sprawdź zapisany adres HTTPS sklepu.';
        }

        if (in_array($response->status(), [401, 403], true)) {
            return $resource === ''
                ? 'PrestaShop odrzucił Klucz API. Sprawdź klucz i jego aktywność.'
                : "Brak uprawnienia odczytu zasobu PrestaShop: {$resource}.";
        }

        return match ($response->status()) {
            404 => $resource === ''
                ? 'Nie znaleziono API PrestaShop. Sprawdź adres sklepu i włączenie usługi Webservice.'
                : "Nie znaleziono zasobu PrestaShop: {$resource}.",
            429 => 'Limit zapytań PrestaShop został chwilowo przekroczony. Spróbuj ponownie później.',
            500, 502, 503, 504 => 'Sklep PrestaShop jest chwilowo niedostępny. Spróbuj ponownie później.',
            default => 'Test połączenia z PrestaShop nie powiódł się.',
        };
    }
}
