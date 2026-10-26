<?php

namespace Modules\Integrations\Prestashop\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Jobs\FetchPrestashopOrderMessagesJob;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Throwable;

class PrestashopOrderMessageService
{
    public function __construct(private readonly PrestashopClient $client) {}

    public function enqueue(int $linkId): void
    {
        DB::afterCommit(function () use ($linkId): void {
            try {
                FetchPrestashopOrderMessagesJob::dispatch($linkId);
            } catch (Throwable) {
                // Comment delivery must never turn a committed order import into a failure.
                $this->recordFailure($linkId, 'dispatch');
            }
        });
    }

    public function sync(int $linkId): int
    {
        $link = PrestashopOrderLink::query()->with(['account', 'order'])->find($linkId);
        $account = $link?->account;

        if (! $link || ! $account?->is_active || ! $link->order || $link->order->trashed()
            || $link->order->source !== 'prestashop' || blank($account->api_key)) {
            return 0;
        }

        $identity = $this->identity($account, $link);
        $remoteOrder = $this->client->findOrder($account, (string) $link->external_order_id);
        $customerId = $this->positiveId($remoteOrder['id_customer'] ?? null);

        if ($this->positiveId($remoteOrder['id'] ?? null) !== $link->external_order_id || $customerId === null) {
            throw new PrestashopApiException('Niezgodna tozsamosc zamowienia komentarzy PrestaShop.');
        }

        $messages = [];
        for ($offset = 0; $offset <= 200; $offset += 100) {
            $page = $this->client->orderMessages($account, $link->external_order_id, $offset);
            foreach ($page as $row) {
                if ($this->positiveId($row['id_order'] ?? null) !== $link->external_order_id
                    || $this->positiveId($row['id_customer'] ?? null) !== $customerId
                    || ! in_array($row['id_employee'] ?? null, [0, '0'], true)
                    || ! in_array($row['private'] ?? null, [false, 0, '0'], true)) {
                    continue;
                }

                $id = $this->positiveId($row['id'] ?? null);
                $body = $this->plainText($row['message'] ?? null);
                if ($id === null || $body === '' || strlen($body) > 65535) {
                    throw new PrestashopApiException('Nieprawidlowe dane komentarza PrestaShop.');
                }

                $message = [
                    'external_customer_id' => $customerId,
                    'message' => $body,
                    'source_created_at' => $this->sourceDate($row['date_add'] ?? null),
                ];
                if (isset($messages[$id]) && $messages[$id] !== $message) {
                    throw new PrestashopApiException('Niejednoznaczny komentarz PrestaShop.');
                }
                $messages[$id] = $message;
            }

            if (count($page) < 100) {
                break;
            }
            if ($offset === 200) {
                throw new PrestashopApiException('Przekroczono limit komentarzy pojedynczego zamowienia PrestaShop.');
            }
        }

        if ($messages === []) {
            return 0;
        }

        $customer = $this->client->customer($account, $customerId);
        if ($this->positiveId($customer['id'] ?? null) !== $customerId) {
            throw new PrestashopApiException('Niezgodny autor komentarzy PrestaShop.');
        }
        $author = mb_substr(trim($this->plainText($customer['firstname'] ?? null).' '.$this->plainText($customer['lastname'] ?? null)), 0, 255);

        return DB::transaction(function () use ($linkId, $account, $identity, $messages, $author): int {
            $lockedAccount = PrestashopAccount::query()->whereKey($account->id)->lockForUpdate()->first();
            $lockedLink = PrestashopOrderLink::query()->whereKey($linkId)->lockForUpdate()->first();
            $lockedOrder = Order::query()->whereKey($lockedLink?->order_id)->lockForUpdate()->first();
            if (! $lockedAccount?->is_active || ! $lockedLink
                || $this->identity($lockedAccount, $lockedLink) !== $identity
                || ! $lockedOrder || $lockedOrder->source !== 'prestashop') {
                throw new PrestashopApiException('Powiazanie komentarzy PrestaShop zmienilo sie podczas pobierania.');
            }

            foreach ($messages as $id => $message) {
                $lockedLink->buyerMessages()->updateOrCreate(
                    ['external_message_id' => $id],
                    $message + ['author_name' => $author ?: 'Klient (PrestaShop)'],
                );
            }

            return count($messages);
        }, 3);
    }

    public function recordFailure(int $linkId, string $stage): void
    {
        Log::warning('PrestaShop: nie pobrano komentarzy kupujacego.', [
            'prestashop_order_link_id' => $linkId,
            'stage' => $stage,
        ]);
    }

    private function identity(PrestashopAccount $account, PrestashopOrderLink $link): array
    {
        return [$link->prestashop_account_id, $link->order_id, $link->external_order_id,
            $account->base_url, $account->getRawOriginal('api_key')];
    }

    private function positiveId(mixed $value): ?int
    {
        return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]*$/D', (string) $value)
            && filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null;
    }

    private function plainText(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim(str_replace(["\r\n", "\r"], "\n", html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function sourceDate(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        if (! is_string($value) || ! CarbonImmutable::canBeCreatedFromFormat($value, 'Y-m-d H:i:s')
            || CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value)->format('Y-m-d H:i:s') !== $value) {
            throw new PrestashopApiException('Nieprawidlowa data komentarza PrestaShop.');
        }

        return $value;
    }
}
