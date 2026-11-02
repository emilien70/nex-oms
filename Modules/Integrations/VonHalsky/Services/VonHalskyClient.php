<?php

namespace Modules\Integrations\VonHalsky\Services;

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Query;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use Modules\Integrations\VonHalsky\Enums\VonHalskyOrderAction;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyApiException;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyCommandUncertainException;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Support\VonHalskyOrderJson;

class VonHalskyClient
{
    public function __construct(private readonly VonHalskyAccountService $accounts) {}

    public function testConnection(VonHalskyAccount $account): void
    {
        $response = $this->get($account, null, ['limit' => 1, 'offset' => 0]);

        if (! isset($response['data'], $response['page'])
            || ! is_array($response['data']) || ! array_is_list($response['data'])
            || count($response['data']) > 1 || ! is_array($response['page'])
            || ($response['page']['limit'] ?? null) !== 1 || ($response['page']['offset'] ?? null) !== 0
            || ! is_int($response['page']['total'] ?? null) || $response['page']['total'] < count($response['data'])) {
            throw new VonHalskyApiException('Von Halsky zwrócił nieprawidłową listę zamówień.');
        }

        foreach ($response['data'] as $order) {
            if (! is_array($order) || ! is_string($order['id'] ?? null)
                || (isset($order['organizationId']) && (! is_string($order['organizationId'])
                    || strtolower($order['organizationId']) !== strtolower($account->organization_id)))) {
                throw new VonHalskyApiException('Lista zamówień Von Halsky nie odpowiada wybranej organizacji.');
            }
        }
    }

    public function order(VonHalskyAccount $account, string $id): array
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,254}\z/D', $id)) {
            throw new VonHalskyApiException('Nieprawidłowy identyfikator zamówienia Von Halsky.');
        }

        $current = $this->accounts->current($account);
        if (! $current->is_active || $current->last_test_status !== 'success') {
            throw new VonHalskyApiException('Najpierw zapisz ustawienia i wykonaj poprawny test połączenia.');
        }

        return $this->get($account, $id);
    }

    public function ordersUpdatedSince(VonHalskyAccount $account, CarbonImmutable $from, int $offset = 0, int $limit = 30): array
    {
        $current = $this->accounts->current($account);
        if (! $current->is_active || $current->last_test_status !== 'success' || $offset < 0 || $limit < 1 || $limit > 30) {
            throw new VonHalskyApiException('Nieprawidłowy kontekst listy zamówień Von Halsky.');
        }
        // OpenAPI form/explode arrays use repeated keys, not PHP bracket notation.
        $result = $this->get($account, null, Query::build(['limit' => $limit, 'offset' => $offset,
            'sort' => ['updatedAt', 'createdAt'], 'paymentStatus' => ['PAID', 'NOT_PAID'],
            'updatedAtGte' => $from->utc()->toISOString()]));
        $page = $result['page'] ?? null;
        $rows = $result['data'] ?? null;
        if (! is_array($page) || ($page['limit'] ?? null) !== $limit || ($page['offset'] ?? null) !== $offset
            || ! is_int($page['total'] ?? null) || $page['total'] < 0 || ! is_array($rows) || ! array_is_list($rows)
            || count($rows) > $limit || ($rows !== [] && $page['total'] < $offset + count($rows))) {
            throw new VonHalskyApiException('Von Halsky zwrócił nieprawidłową stronę zamówień.');
        }

        return $result;
    }

    public function prepareOrderCommand(VonHalskyAccount $account): void
    {
        $current = $this->accounts->current($account);
        if (! $current->status_sync_enabled || ! $current->is_active || $current->status_sync_revision !== $account->status_sync_revision) {
            throw new VonHalskyApiException('Przekazywanie statusów Von Halsky zostało wyłączone lub zmienione.');
        }
        $this->token($account, write: true);
    }

    public function orderCommand(VonHalskyAccount $account, string $id, VonHalskyOrderAction $action): array
    {
        $this->validateAccount($account);
        $current = $this->accounts->current($account);
        if (! $current->status_sync_enabled || ! $current->is_active || $current->last_test_status !== 'success'
            || $current->status_sync_revision !== $account->status_sync_revision
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,254}\z/D', $id)) {
            throw new VonHalskyApiException('Przekazywanie statusów Von Halsky nie jest aktywne lub kontekst jest nieprawidłowy.');
        }
        $token = $this->token($account, write: true);
        $this->checkCooldown($account);
        $current = $this->accounts->current($account);
        if (! $current->status_sync_enabled || ! $current->is_active || $current->last_test_status !== 'success'
            || $current->status_sync_revision !== $account->status_sync_revision) {
            throw new VonHalskyApiException('Przekazywanie statusów Von Halsky zostało wyłączone.');
        }
        $url = $account->environment->apiUrl().'/v1/organizations/'.rawurlencode($account->organization_id)
            .'/orders/'.rawurlencode($id).'/'.$action->endpoint();
        try {
            $response = $this->request(write: true)->withToken($token)->send('POST', $url);
        } catch (ConnectionException) {
            throw new VonHalskyCommandUncertainException;
        }
        // A command POST is never automatically repeated, including after HTTP 401.
        if (in_array($response->status(), [400, 401, 403, 404, 422, 429], true)) {
            if ($response->status() === 401) {
                $this->forgetToken($account);
            }
            if ($response->status() === 422) {
                throw new VonHalskyApiException('Von Halsky nie pozwala wykonać tej operacji dla bieżącego stanu zamówienia.', 422);
            }
            if (in_array($response->status(), [400, 401, 403], true)) {
                throw new VonHalskyApiException('Von Halsky odrzucił przekazanie statusu. Sprawdź połączenie i uprawnienie api:orders:write.', $response->status());
            }
            $this->checkResponse($account, $response);
        }
        if ($response->status() !== 202) {
            throw new VonHalskyCommandUncertainException;
        }
        $commandId = null;
        try {
            $data = $this->decode($response);
            $commandId = is_string($data['commandId'] ?? null) && Str::isUuid($data['commandId']) ? strtolower($data['commandId']) : null;

            return $this->commandData($data);
        } catch (VonHalskyApiException) {
            throw new VonHalskyCommandUncertainException($commandId);
        }
    }

    public function orderCommandStatus(VonHalskyAccount $account, string $commandId): array
    {
        if (! Str::isUuid($commandId)) {
            throw new VonHalskyApiException('Nieprawidłowy identyfikator polecenia Von Halsky.');
        }

        return $this->commandData($this->get($account, null, commandId: $commandId), $commandId);
    }

    private function commandData(array $data, ?string $expected = null): array
    {
        if (! is_string($data['commandId'] ?? null) || ! Str::isUuid($data['commandId'])
            || ($expected !== null && strtolower($data['commandId']) !== strtolower($expected))
            || ! in_array($data['status'] ?? null, ['PENDING', 'SUCCESS', 'FAILURE'], true)) {
            throw new VonHalskyApiException('Von Halsky zwrócił nieprawidłowy wynik polecenia.');
        }

        return ['commandId' => strtolower($data['commandId']), 'status' => $data['status']];
    }

    private function get(VonHalskyAccount $account, ?string $id, array|string $query = [], ?string $commandId = null): array
    {
        $this->validateAccount($account);
        $path = '/v1/organizations/'.rawurlencode($account->organization_id).'/orders';
        $url = $account->environment->apiUrl().$path.($id === null ? '' : '/'.rawurlencode($id));
        if ($commandId !== null) {
            $url .= '/commands/'.rawurlencode($commandId);
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->token($account);
            $this->checkCooldown($account);

            try {
                $response = $this->request()->withToken($token)->get($url, $query);
            } catch (ConnectionException) {
                throw new VonHalskyApiException('Nie udało się połączyć z Von Halsky. Spróbuj ponownie później.');
            }

            $this->accounts->current($account);

            if ($response->status() === 401 && $attempt === 0) {
                $this->forgetToken($account);

                continue;
            }

            if (in_array($response->status(), [401, 403], true)) {
                $this->accounts->recordTest($account, $this->errorMessage($response->status()));
            }

            $this->checkResponse($account, $response);

            return $this->decode($response, $id !== null);
        }

        throw new VonHalskyApiException('Nie udało się potwierdzić dostępu do Von Halsky.');
    }

    private function token(VonHalskyAccount $context, bool $write = false): string
    {
        $account = $this->accounts->current($context);
        if (filled($account->access_token) && $account->token_expires_at?->isAfter(now()->addSeconds(30))
            && (! $write || in_array('api:orders:write', $account->access_token_scopes ?? [], true))) {
            return $account->access_token;
        }

        $this->checkCooldown($account);

        try {
            $response = $this->request($write)->asForm()->post($account->environment->tokenUrl(), [
                'grant_type' => 'client_credentials',
                'scope' => $write ? 'openid api:orders:read api:orders:write' : 'openid api:orders:read',
                'client_id' => $account->client_id,
                'client_secret' => $account->client_secret,
            ]);
        } catch (ConnectionException) {
            throw new VonHalskyApiException('Nie udało się połączyć z serwerem autoryzacji Von Halsky.');
        }

        $this->accounts->current($context);
        if (! $write && in_array($response->status(), [401, 403], true)) {
            $this->accounts->recordTest($context, $this->errorMessage($response->status()));
        }
        if ($write && in_array($response->status(), [400, 401, 403], true)) {
            throw new VonHalskyApiException('Nie udało się uzyskać tokenu z uprawnieniem api:orders:write.', $response->status());
        }
        $this->checkResponse($account, $response);
        $data = $this->decode($response);
        $expires = $data['expires_in'] ?? null;
        $token = $data['access_token'] ?? null;
        if (! is_string($token) || ! preg_match('/\A[A-Za-z0-9._~+\/-]+=*\z/D', $token)
            || strlen($token) > 16384
            || ! is_string($data['token_type'] ?? null) || strtolower($data['token_type']) !== 'bearer'
            || ! is_int($expires) || $expires <= 30 || $expires > 86400) {
            throw new VonHalskyApiException('Serwer autoryzacji Von Halsky zwrócił nieprawidłowy token.');
        }

        if (isset($data['scope']) && (! is_string($data['scope'])
            || ! in_array('api:orders:read', explode(' ', $data['scope']), true))) {
            throw new VonHalskyApiException('Aplikacja Von Halsky nie ma uprawnienia api:orders:read.');
        }
        if ($write && isset($data['scope']) && ! in_array('api:orders:write', explode(' ', $data['scope']), true)) {
            throw new VonHalskyApiException('Aplikacja Von Halsky nie ma uprawnienia api:orders:write.');
        }
        $scopes = isset($data['scope']) ? array_values(array_unique(explode(' ', $data['scope'])))
            : ($write ? ['openid', 'api:orders:read', 'api:orders:write'] : ['openid', 'api:orders:read']);

        DB::transaction(function () use ($context, $token, $expires, $scopes): void {
            $changes = [
                'access_token' => $token,
                'token_expires_at' => now()->addSeconds($expires),
            ];
            if (Schema::hasColumn('von_halsky_accounts', 'access_token_scopes')) {
                $changes['access_token_scopes'] = $scopes;
            }
            $this->accounts->current($context, lock: true)->forceFill($changes)->save();
        });

        return $token;
    }

    private function forgetToken(VonHalskyAccount $context): void
    {
        DB::transaction(function () use ($context): void {
            $this->accounts->current($context, lock: true)->forceFill([
                'access_token' => null,
                'token_expires_at' => null,
            ])->save();
        });
    }

    private function request(bool $write = false): PendingRequest
    {
        return Http::acceptJson()->withHeaders([
            'Accept-Language' => 'pl',
            'User-Agent' => $write ? 'NEX-OMS/1.0 (VonHalsky)' : 'NEX-OMS/1.0 (VonHalsky read-only)',
        ])->connectTimeout(5)->timeout(20)->withoutRedirecting();
    }

    private function validateAccount(VonHalskyAccount $account): void
    {
        if (! $account->exists || ! $account->hasCompleteCredentials() || ! Str::isUuid($account->organization_id)) {
            throw new VonHalskyApiException('Uzupełnij i zapisz dane połączenia Von Halsky.');
        }
    }

    private function checkResponse(VonHalskyAccount $account, Response $response): void
    {
        if ($response->status() === 200) {
            return;
        }

        $retryAfter = null;
        if ($response->status() === 429) {
            $header = $response->header('Retry-After');
            $retryAfter = ctype_digit($header)
                ? (int) $header
                : max(0, (strtotime($header) ?: 0) - now()->timestamp);
            $retryAfter = max(1, min($retryAfter ?: 60, 86400));
            Cache::put($this->cooldownKey($account), now()->timestamp + $retryAfter, $retryAfter);
        }

        throw new VonHalskyApiException($this->errorMessage($response->status()), $response->status(), $retryAfter);
    }

    private function checkCooldown(VonHalskyAccount $account): void
    {
        $remaining = (int) Cache::get($this->cooldownKey($account), 0) - now()->timestamp;
        if ($remaining > 0) {
            throw new VonHalskyApiException('Limit zapytań Von Halsky. Odczekaj przed kolejną próbą.', 429, $remaining);
        }
    }

    private function cooldownKey(VonHalskyAccount $account): string
    {
        return 'von-halsky:cooldown:'.hash('sha256', $account->environment->value.':'.$account->organization_id);
    }

    private function decode(Response $response, bool $preserveNumbers = false): array
    {
        if (strlen($response->body()) > 2_000_000) {
            throw new VonHalskyApiException('Odpowiedź Von Halsky przekracza dozwolony rozmiar.');
        }

        try {
            $data = $preserveNumbers ? VonHalskyOrderJson::decode($response->body())
                : json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new VonHalskyApiException('Von Halsky zwrócił nieprawidłową odpowiedź JSON.');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new VonHalskyApiException('Von Halsky zwrócił nieprawidłową strukturę odpowiedzi.');
        }

        return $data;
    }

    private function errorMessage(int $status): string
    {
        return match ($status) {
            400 => 'Von Halsky odrzucił żądanie. Sprawdź dane aplikacji i organizacji oraz uprawnienie api:orders:read.',
            401 => 'Von Halsky odrzucił autoryzację. Sprawdź Client ID, Client Secret i środowisko.',
            403 => 'Brak dostępu do zamówień tej organizacji. Sprawdź Organization ID i uprawnienia aplikacji.',
            404 => 'Nie znaleziono zamówienia lub organizacji w wybranym środowisku Von Halsky.',
            429 => 'Limit zapytań Von Halsky. Odczekaj przed kolejną próbą.',
            default => 'Usługa Von Halsky jest niedostępna lub zwróciła nieoczekiwaną odpowiedź. Spróbuj ponownie później.',
        };
    }
}
