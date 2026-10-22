<?php

namespace Modules\Integrations\Allegro\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Enums\AllegroFulfillmentStatus;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroCommissionRefundClaim;
use Modules\Integrations\Allegro\Models\AllegroRefundCommand;
use Modules\Shipments\Models\IntegrationApiLog;
use Throwable;

class AllegroClient
{
    private const MEDIA_TYPE = 'application/vnd.allegro.public.v1+json';

    public function __construct(private readonly AllegroOAuthService $oauth) {}

    public function testConnection(AllegroAccount $account): array
    {
        if (! $account->hasRequiredScopes()) {
            throw new AllegroApiException(
                'Połączenie nie posiada wymaganych uprawnień: '.implode(', ', $account->missingScopes()).'. Połącz konto ponownie.',
            );
        }

        return [
            'profile' => $this->get($account, 'test_profile', '/me'),
            'order_stats' => $this->get($account, 'test_orders', '/order/event-stats'),
            'delivery_services' => $this->get($account, 'test_shipments', '/shipment-management/delivery-services'),
        ];
    }

    public function order(AllegroAccount $account, string $identifier): array
    {
        if (! $account->isOperational() || blank($account->allegro_user_id)) {
            throw new AllegroApiException('Najpierw połącz konto Allegro i wykonaj poprawny test połączenia.');
        }

        return $this->get($account, 'get_order', '/order/checkout-forms/'.rawurlencode($identifier));
    }

    public function billingEntries(AllegroAccount $account, string $identifier, string $marketplace, int $offset): array
    {
        if (! $account->isOperational() || ! $account->hasBillingScope()) {
            throw new AllegroApiException('Połącz konto Allegro z uprawnieniem do odczytu rozliczeń.');
        }

        return $this->get($account, 'get_order_fees', '/billing/billing-entries?'.http_build_query([
            'order.id' => $identifier,
            'marketplaceId' => $marketplace,
            'limit' => 100,
            'offset' => $offset,
        ], '', '&', PHP_QUERY_RFC3986));
    }

    public function eventStats(AllegroAccount $account): array
    {
        return $this->get($account, 'order_event_stats', '/order/event-stats');
    }

    public function orderEvents(AllegroAccount $account, ?string $cursor): array
    {
        return $this->get($account, 'order_events', '/order/events?'.http_build_query(array_filter([
            'from' => $cursor, 'limit' => 100,
        ], fn ($value) => $value !== null && $value !== ''), '', '&', PHP_QUERY_RFC3986));
    }

    public function incompleteOrders(AllegroAccount $account, int $offset, string $from): array
    {
        return $this->get($account, 'incomplete_orders', '/order/checkout-forms?status=BOUGHT&status=FILLED_IN&limit=100&offset='.$offset
            .'&lineItems.boughtAt.gte='.rawurlencode($from));
    }

    public function updateFulfillment(AllegroAccount $account, string $identifier, AllegroFulfillmentStatus $status, string $revision): void
    {
        if (! $account->isOperational() || blank($revision)) {
            throw new AllegroApiException('Brak aktywnego połączenia lub aktualnej wersji zamówienia Allegro.', 422);
        }
        $this->request($account, 'set_fulfillment', '/order/checkout-forms/'.rawurlencode($identifier)
            .'/fulfillment?checkoutForm.revision='.rawurlencode($revision), 'PUT', ['status' => $status->value]);
    }

    private function get(AllegroAccount $account, string $operation, string $path): array
    {
        return $this->request($account, $operation, $path);
    }

    public function customerReturns(AllegroAccount $account, array $query): array
    {
        return $this->get($account, 'get_customer_returns', '/order/customer-returns?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function customerReturn(AllegroAccount $account, string $id): array
    {
        return $this->get($account, 'get_customer_return', '/order/customer-returns/'.rawurlencode($id));
    }

    public function refunds(AllegroAccount $account, array $query): array
    {
        if (! $account->isOperational() || ! in_array(AllegroAccount::PAYMENTS_READ_SCOPE, $account->granted_scopes ?? [], true)) {
            throw new AllegroApiException('Połącz konto Allegro z uprawnieniem do odczytu płatności.', 403);
        }

        return $this->get($account, 'get_payment_refunds', '/payments/refunds?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function orderShipments(AllegroAccount $account, string $identifier): array
    {
        return $this->get($account, 'get_order_shipments', '/order/checkout-forms/'.rawurlencode($identifier).'/shipments');
    }

    public function initiateRefund(AllegroAccount $account, AllegroRefundCommand $command): array
    {
        $payload = $command->payload;
        if (! $account->isOperational() || ! $account->refund_write_enabled
            || $account->refund_write_identity !== $account->orderImportIdentity()
            || ! in_array(AllegroAccount::PAYMENTS_WRITE_SCOPE, $account->granted_scopes ?? [], true)
            || ! in_array(AllegroAccount::PAYMENTS_READ_SCOPE, $account->granted_scopes ?? [], true)
            || ! $command->exists || $command->state !== 'sending' || ! $command->started_at
            || $command->allegro_account_id !== $account->id || $command->environment !== $account->environment
            || $command->seller_user_id !== $account->allegro_user_id || $command->configuration_revision !== $account->refund_write_revision
            || $command->payload_hash !== AllegroRefundContextService::hash($payload)
            || $command->command_id !== ($payload['commandId'] ?? null) || ! Str::isUuid($payload['commandId'] ?? '')) {
            throw new AllegroApiException('Zlecanie zwrotów wpłat jest wyłączone lub brak wymaganych uprawnień.', 403);
        }

        return $this->request($account, 'initiate_payment_refund', '/payments/refunds', 'POST', $payload);
    }

    public function carriers(AllegroAccount $account): array
    {
        return $this->get($account, 'get_order_carriers', '/order/carriers');
    }

    public function commissionRefundClaims(AllegroAccount $account, array $query): array
    {
        if (! $account->isOperational()) {
            throw new AllegroApiException('Brak aktywnego połączenia z kontem Allegro.', 403);
        }

        return $this->get($account, 'get_commission_refund_claims', '/order/refund-claims?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function commissionRefundClaim(AllegroAccount $account, string $id): array
    {
        if (! $account->isOperational()) {
            throw new AllegroApiException('Brak aktywnego połączenia z kontem Allegro.', 403);
        }

        return $this->get($account, 'get_commission_refund_claim', '/order/refund-claims/'.rawurlencode($id));
    }

    public function createCommissionRefundClaim(AllegroAccount $account, AllegroCommissionRefundClaim $claim): array
    {
        if (! $account->isOperational() || ! $claim->exists || $claim->state !== 'sending' || ! $claim->started_at
            || $account->id !== $claim->allegro_account_id || $account->environment !== $claim->environment
            || $account->allegro_user_id !== $claim->seller_user_id
            || AllegroCommissionRefundService::accountIdentity($account) !== $claim->account_identity) {
            throw new AllegroApiException('Konto lub wniosek zmieniły się przed wysłaniem.', 403);
        }

        return $this->request($account, 'create_commission_refund_claim', '/order/refund-claims', 'POST',
            ['lineItem' => ['id' => $claim->line_item_id], 'quantity' => $claim->quantity]);
    }

    public function addOrderShipment(AllegroAccount $account, string $identifier, string $carrierId, string $waybill, array $lineItemIds): array
    {
        return $this->request($account, 'add_order_shipment', '/order/checkout-forms/'.rawurlencode($identifier).'/shipments', 'POST', [
            'carrierId' => $carrierId, 'waybill' => $waybill,
            'lineItems' => array_map(fn (string $id): array => ['id' => $id], $lineItemIds),
        ]);
    }

    public function orderInvoices(AllegroAccount $account, string $identifier): array
    {
        return $this->get($account, 'get_order_invoices', '/order/checkout-forms/'.rawurlencode($identifier).'/invoices');
    }

    public function uploadOrderInvoice(AllegroAccount $account, string $identifier, string $number, string $filename, string $contents): array
    {
        return $this->request($account, 'upload_order_invoice', '/order/'.rawurlencode($identifier).'/billing-documents/files', 'POST',
            ['invoiceNumber' => $number], ['name' => $filename, 'contents' => $contents]);
    }

    private function request(AllegroAccount $account, string $operation, string $path, string $method = 'GET', array $data = [], array $file = []): array
    {
        $requestId = (string) Str::uuid();
        $url = $account->apiBaseUrl().$path;
        $startedAt = hrtime(true);

        try {
            $response = $this->perform($account, $url, $method, $data, $file);

            if ($response->status() === 401) {
                $this->oauth->refresh($account);
                $freshAccount = $account->fresh();
                if ($operation === 'initiate_payment_refund' && ($freshAccount->orderImportIdentity() !== $account->orderImportIdentity()
                    || $freshAccount->refund_write_revision !== $account->refund_write_revision || ! $freshAccount->refund_write_enabled
                    || ! $freshAccount->isOperational() || ! in_array(AllegroAccount::PAYMENTS_WRITE_SCOPE, $freshAccount->granted_scopes ?? [], true))) {
                    throw new AllegroApiException('Konto zmieniło się podczas autoryzacji refundacji.');
                }
                if ($operation === 'create_commission_refund_claim' && (! $freshAccount->isOperational()
                    || AllegroCommissionRefundService::accountIdentity($freshAccount) !== AllegroCommissionRefundService::accountIdentity($account))) {
                    throw new AllegroApiException('Konto zmieniło się podczas autoryzacji wniosku o prowizję.');
                }
                $response = $this->perform($freshAccount, $url, $method, $data, $file);
            }

            $payload = is_array($response->json()) ? $response->json() : [];
            $successful = $response->successful();
            $error = $successful ? null : match ($operation) {
                'get_order' => $this->orderErrorMessage($response->status()),
                'get_order_fees' => $this->feesErrorMessage($response->status()),
                'set_fulfillment' => $this->fulfillmentErrorMessage($response->status()),
                'get_order_shipments', 'get_order_carriers', 'add_order_shipment' => $this->shipmentErrorMessage($response->status()),
                'get_order_invoices', 'upload_order_invoice' => $this->invoiceErrorMessage($response->status()),
                'get_customer_returns', 'get_customer_return' => 'Nie udało się pobrać zwrotów Allegro (HTTP '.$response->status().').',
                'get_payment_refunds' => 'Nie udało się pobrać refundacji Allegro (HTTP '.$response->status().').',
                'initiate_payment_refund' => 'Allegro odpowiedziało na zlecenie zwrotu wpłaty kodem HTTP '.$response->status().'.',
                'create_commission_refund_claim', 'get_commission_refund_claim', 'get_commission_refund_claims' => 'Nie udało się obsłużyć wniosku o zwrot prowizji (HTTP '.$response->status().').',
                default => $this->errorMessage($payload, $response->status()),
            };
            $this->log(
                $operation,
                $requestId,
                $url,
                $response->status(),
                $this->sanitizedResponse($operation, $payload),
                $startedAt,
                $successful,
                $error,
                $method,
            );

            if (! $successful) {
                throw new AllegroApiException($error, $response->status(), $payload);
            }

            return $payload;
        } catch (AllegroApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (in_array($operation, ['create_commission_refund_claim', 'get_commission_refund_claim', 'get_commission_refund_claims'], true)) {
                $this->log($operation, $requestId, $url, null, null, $startedAt, false, 'Nie potwierdzono wyniku obsługi wniosku o zwrot prowizji.', $method);
                throw new AllegroApiException('Nie potwierdzono wyniku obsługi wniosku o zwrot prowizji.');
            }
            if (in_array($operation, ['initiate_payment_refund', 'get_payment_refunds'], true)) {
                $this->log($operation, $requestId, $url, null, null, $startedAt, false, 'Nie potwierdzono wyniku połączenia dotyczącego refundacji.', $method);
                throw new AllegroApiException('Nie potwierdzono wyniku połączenia dotyczącego refundacji.');
            }
            if (in_array($operation, ['get_customer_returns', 'get_customer_return'], true)) {
                $this->log($operation, $requestId, $url, null, null, $startedAt, false, 'Błąd połączenia podczas pobierania zwrotów.', $method);
                throw new AllegroApiException('Nie udało się połączyć z Allegro podczas pobierania zwrotów.');
            }
            $invoiceOperation = in_array($operation, ['get_order_invoices', 'upload_order_invoice'], true);
            $this->log($operation, $requestId, $url, null, null, $startedAt, false,
                $invoiceOperation ? 'Błąd połączenia podczas obsługi faktury Allegro.' : $exception->getMessage(), $method);

            if ($invoiceOperation) {
                throw new AllegroApiException('Nie udało się potwierdzić wyniku obsługi faktury w Allegro.');
            }

            throw new AllegroApiException(in_array($operation, ['get_order_shipments', 'get_order_carriers', 'add_order_shipment'], true)
                ? 'Nie udało się połączyć z Allegro podczas przekazywania numeru przesyłki.'
                : 'Nie udało się połączyć z API Allegro: '.$exception->getMessage());
        }
    }

    private function perform(AllegroAccount $account, string $url, string $method = 'GET', array $data = [], array $file = []): Response
    {
        $request = Http::withToken($this->oauth->accessToken($account))
            ->withUserAgent((string) config('services.allegro.user_agent'))
            ->withHeaders(['Accept' => str_starts_with((string) parse_url($url, PHP_URL_PATH), '/order/customer-returns')
                ? 'application/vnd.allegro.beta.v1+json' : self::MEDIA_TYPE, 'Accept-Language' => $file === [] ? 'pl-PL' : 'en-US'])
            ->timeout((int) config('services.allegro.timeout', 25));
        if ($file !== []) {
            return $request->attach('file', $file['contents'], $file['name'], ['Content-Type' => 'application/pdf'])->post($url, $data);
        }

        return $request->withHeaders(['Content-Type' => self::MEDIA_TYPE])->send($method, $url, $data === [] ? [] : ['json' => $data]);
    }

    private function invoiceErrorMessage(int $status): string
    {
        return match ($status) {
            401 => 'Sesja Allegro wygasła. Połącz konto ponownie.',
            403 => 'Brak uprawnień do obsługi faktur tego zamówienia Allegro.',
            404 => 'Nie znaleziono zamówienia Allegro.',
            409 => 'W Allegro istnieje już dokument lub plik o tej nazwie. Wymagane jest sprawdzenie.',
            413 => 'PDF przekracza limit 3 MB Allegro.',
            400, 422 => 'Allegro odrzuciło fakturę, jej numer lub plik. Sprawdź dokument oraz zamówienie.',
            429 => 'Przekroczono limit zapytań Allegro. Spróbuj ponownie później.',
            default => 'Nie udało się potwierdzić obsługi faktury w Allegro (HTTP '.$status.').',
        };
    }

    private function fulfillmentErrorMessage(int $status): string
    {
        return match ($status) {
            401 => 'Sesja Allegro wygasła. Połącz konto ponownie.',
            403 => 'Brak uprawnień do zmiany statusu zamówienia Allegro.',
            404 => 'Nie znaleziono zamówienia na połączonym koncie Allegro.',
            409 => 'Zamówienie Allegro zmieniło się podczas przekazywania statusu. Sprawdź jego aktualny stan.',
            422 => 'Allegro nie zezwala na ustawienie wybranego statusu realizacji.',
            429 => 'Przekroczono limit zapytań Allegro. Zmiana zostanie ponowiona.',
            default => 'Nie udało się przekazać statusu do Allegro (HTTP '.$status.').',
        };
    }

    private function shipmentErrorMessage(int $status): string
    {
        return match ($status) {
            401 => 'Sesja Allegro wygasła. Połącz konto ponownie.',
            403 => 'Brak uprawnień do obsługi numerów przesyłek tego zamówienia Allegro.',
            404 => 'Nie znaleziono zamówienia lub zasobu przesyłek Allegro.',
            400, 422 => 'Allegro odrzuciło dane przewoźnika, numer przesyłki lub powiązanie z pozycjami zamówienia.',
            429 => 'Przekroczono limit zapytań Allegro. Przekazanie numeru zostanie ponowione.',
            default => 'Nie udało się obsłużyć numeru przesyłki w Allegro (HTTP '.$status.').',
        };
    }

    private function errorMessage(array $payload, int $status): string
    {
        $messages = collect((array) ($payload['errors'] ?? []))
            ->map(fn (mixed $error): ?string => is_array($error)
                ? ($error['userMessage'] ?? $error['message'] ?? $error['code'] ?? null)
                : null)
            ->filter();

        return $messages->isNotEmpty()
            ? $messages->unique()->implode(' ')
            : (string) ($payload['message'] ?? 'API Allegro zwróciło błąd HTTP '.$status.'.');
    }

    private function orderErrorMessage(int $status): string
    {
        return match ($status) {
            401 => 'Sesja Allegro wygasła. Połącz konto ponownie.',
            403 => 'Brak dostępu do tego zamówienia Allegro. Sprawdź konto sprzedawcy i uprawnienia integracji.',
            404 => 'Nie znaleziono zamówienia Allegro na połączonym koncie.',
            429 => 'Przekroczono limit zapytań Allegro. Spróbuj ponownie później.',
            default => 'Nie udało się pobrać zamówienia Allegro (HTTP '.$status.'). Spróbuj ponownie później.',
        };
    }

    private function feesErrorMessage(int $status): string
    {
        return match ($status) {
            401 => 'Sesja Allegro wygasła. Połącz konto ponownie.',
            403 => 'Brak dostępu do rozliczeń Allegro. Włącz odczyt rozliczeń w aplikacji Allegro i ponownie połącz integrację.',
            429 => 'Przekroczono limit zapytań Allegro. Spróbuj ponownie później.',
            default => 'Nie udało się pobrać prowizji i opłat Allegro (HTTP '.$status.'). Spróbuj ponownie później.',
        };
    }

    private function sanitizedResponse(string $operation, array $payload): array
    {
        if (in_array($operation, ['create_commission_refund_claim', 'get_commission_refund_claim', 'get_commission_refund_claims'], true)) {
            return [];
        }
        if ($operation === 'initiate_payment_refund') {
            return [];
        }
        if ($operation === 'get_payment_refunds') {
            return ['count' => is_array($payload['refunds'] ?? null) ? count($payload['refunds']) : null];
        }
        if (in_array($operation, ['get_customer_returns', 'get_customer_return'], true)) {
            return ['count' => is_array($payload['customerReturns'] ?? null) ? count($payload['customerReturns']) : null];
        }
        if (in_array($operation, ['get_order_invoices', 'upload_order_invoice'], true)) {
            return ['count' => is_array($payload['invoices'] ?? null) ? count($payload['invoices']) : null];
        }
        if (in_array($operation, ['get_order_shipments', 'get_order_carriers', 'add_order_shipment'], true)) {
            return ['count' => count($payload[$operation === 'get_order_carriers' ? 'carriers' : 'shipments'] ?? [])];
        }
        if ($operation === 'set_fulfillment') {
            return [];
        }
        if (in_array($operation, ['order_events', 'incomplete_orders'], true)) {
            return ['count' => count($payload[$operation === 'order_events' ? 'events' : 'checkoutForms'] ?? [])];
        }
        if ($operation === 'get_order_fees') {
            return ['count' => is_array($payload['billingEntries'] ?? null) ? count($payload['billingEntries']) : null];
        }

        if ($operation === 'get_order') {
            return collect($payload)->only(['id', 'status', 'revision', 'updatedAt'])->all();
        }

        if ($operation !== 'test_profile') {
            return $payload;
        }

        return [
            'id' => $payload['id'] ?? null,
            'login' => $payload['login'] ?? null,
            'baseMarketplace' => ['id' => data_get($payload, 'baseMarketplace.id')],
        ];
    }

    private function log(
        string $operation,
        string $requestId,
        string $url,
        ?int $status,
        ?array $response,
        int $startedAt,
        bool $successful,
        ?string $error,
        string $method = 'GET',
    ): void {
        IntegrationApiLog::query()->create([
            'integration' => 'allegro',
            'operation' => $operation,
            'request_id' => $requestId,
            'method' => $method,
            'url' => $url,
            'response_status' => $status,
            'response_payload' => $response,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'successful' => $successful,
            'error_message' => $error,
        ]);
    }
}
