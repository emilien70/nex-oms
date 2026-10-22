<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\OrderReturn;
use App\Services\OrderReturnRefundService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Jobs\ProcessAllegroCommissionRefundJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroCommissionRefundClaim;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Throwable;

class AllegroCommissionRefundService
{
    public function __construct(private readonly AllegroClient $client) {}

    public static function accountIdentity(AllegroAccount $account): string
    {
        return hash('sha256', $account->orderImportIdentity().'|'.$account->client_id);
    }

    public function context(OrderReturn $return): array
    {
        $return->load(['items.orderItem', 'order.allegroOrderLink', 'localStatus']);
        $identity = app(OrderReturnRefundService::class)->identity($return);
        $link = $return->order?->allegroOrderLink;
        $account = $identity ? AllegroAccount::query()->where('environment', $identity['environment'])
            ->where('allegro_user_id', $identity['seller_user_id'])->first() : null;
        if (! $identity || ! $link || $return->order->trashed() || $link->replaced_by_link_id || $link->only(array_keys($identity)) !== $identity
            || ! $account?->isOperational() || $return->localStatus?->code === 'rejected') {
            $this->invalid('Wymagane jest aktywne konto i jednoznaczne powiązanie zwrotu z zamówieniem Allegro.');
        }
        $raw = $this->client->order($account, $identity['external_order_id']);
        $buyer = data_get($raw, 'buyer.id');
        if (($raw['id'] ?? null) !== $identity['external_order_id'] || ! is_string($buyer) || trim($buyer) === '' || strlen($buyer) > 100
            || ! is_array($raw['lineItems'] ?? null) || ! array_is_list($raw['lineItems']) || count($raw['lineItems']) > 1000) {
            $this->invalid('Allegro zwróciło niekompletne dane zamówienia.');
        }
        $source = [];
        foreach ($raw['lineItems'] as $item) {
            $id = $item['id'] ?? '';
            $offer = data_get($item, 'offer.id');
            if (! Str::isUuid($id) || isset($source[$id]) || ! is_int($item['quantity'] ?? null) || $item['quantity'] < 1
                || $item['quantity'] > 1000000 || ! is_string($offer) || $offer === '' || strlen($offer) > 100) {
                $this->invalid('Niejednoznaczne pozycje zamówienia Allegro.');
            }
            $source[$id] = ['id' => $id, 'offer_id' => $offer, 'purchased_quantity' => $item['quantity']];
        }
        $rows = [];
        $seen = [];
        foreach ($return->items as $item) {
            $id = $item->orderItem?->external_id;
            if (! $id || ! isset($source[$id]) || isset($seen[$id]) || $item->orderItem->order_id !== $return->order_id
                || $item->quantity < 1 || $item->quantity > $source[$id]['purchased_quantity']
                || (filled($item->offer_id) && (string) $item->offer_id !== $source[$id]['offer_id'])) {
                $this->invalid('Pozycja zwrotu nie ma jednoznacznego powiązania z oryginalną pozycją Allegro.');
            }
            $seen[$id] = true;
            $rows[$item->id] = $source[$id] + ['name' => $item->name, 'max' => $item->quantity];
        }
        if ($rows === [] || count($rows) > 100) {
            $this->invalid('Wniosek wymaga od 1 do 100 jednoznacznych pozycji zwrotu.');
        }
        $hash = AllegroRefundContextService::hash([$identity, self::accountIdentity($account), $buyer, $rows, $return->lock_version]);
        $known = $this->findClaims($account, $buyer);
        foreach ($rows as $row) {
            $existing = $known[$row['id']] ?? null;
            if ($existing && ($existing['offer_id'] !== $row['offer_id'] || $existing['purchased_quantity'] !== $row['purchased_quantity'])) {
                $this->invalid('Istniejący wniosek nie odpowiada pozycji zamówienia.');
            }
        }
        $local = AllegroCommissionRefundClaim::query()->where($identity)->get()->keyBy('line_item_id');

        return ['account' => $account, 'identity' => $identity, 'buyer_id' => $buyer, 'rows' => $rows, 'hash' => $hash,
            'known' => $known, 'local' => $local, 'link_id' => $link->id, 'return_version' => $return->lock_version];
    }

    public function submit(OrderReturn $return, array $data, array $quote): void
    {
        if (($quote['token'] ?? null) !== ($data['quote_token'] ?? null) || ($quote['expires'] ?? 0) < now()->timestamp) {
            $this->invalid('Potwierdzenie wygasło lub należy do innej sesji. Otwórz formularz ponownie.');
        }
        $context = $this->context($return);
        if ($context['hash'] !== ($quote['hash'] ?? null)) {
            $this->invalid('Dane zwrotu lub konta zmieniły się. Otwórz formularz ponownie.');
        }
        $selected = [];
        foreach ($data['items'] as $key => $input) {
            if (! isset($context['rows'][$key])) {
                $this->invalid('Nieznana pozycja zwrotu.');
            }
            if (empty($input['selected'])) {
                continue;
            }
            $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (! $quantity || $quantity < 1 || $quantity > $context['rows'][$key]['max']) {
                $this->invalid('Ilość przekracza liczbę sztuk na karcie zwrotu.');
            }
            $selected[$key] = $quantity;
        }
        if ($selected === []) {
            $this->invalid('Wybierz przynajmniej jedną pozycję.');
        }
        $ids = DB::transaction(function () use ($return, $data, $quote, $context, $selected): array {
            $account = AllegroAccount::query()->lockForUpdate()->findOrFail($context['account']->id);
            $link = AllegroOrderLink::query()->lockForUpdate()->findOrFail($context['link_id']);
            $currentReturn = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);
            $this->guard($account, $link, $currentReturn, $context);
            $ids = [];
            foreach ($selected as $key => $quantity) {
                $row = $context['rows'][$key];
                $claim = AllegroCommissionRefundClaim::query()->where('environment', $account->environment)->where('seller_user_id', $account->allegro_user_id)
                    ->where('line_item_id', $row['id'])->lockForUpdate()->first();
                if ($claim && ($claim->external_order_id !== $context['identity']['external_order_id'] || $claim->buyer_id !== $context['buyer_id'])) {
                    $this->invalid('Tożsamość pozycji w zapisanym wniosku jest niespójna.');
                }
                if ($claim && ($claim->state !== 'not_sent' || $claim->started_at || $claim->external_claim_id)) {
                    continue;
                }
                $claim ??= new AllegroCommissionRefundClaim;
                $claim->fill($context['identity'] + [
                    'allegro_account_id' => $account->id, 'order_id' => $return->order_id, 'order_return_id' => $return->id, 'return_number' => $return->id,
                    'line_item_id' => $row['id'], 'offer_id' => $row['offer_id'], 'buyer_id' => $context['buyer_id'], 'product_name' => $row['name'],
                    'quantity' => $quantity, 'purchased_quantity' => $row['purchased_quantity'], 'request_key' => $data['quote_token'],
                    'context_hash' => $context['hash'], 'account_identity' => self::accountIdentity($account), 'return_version' => $currentReturn->lock_version,
                    'expires_at' => CarbonImmutable::createFromTimestamp($quote['expires'])->setTimezone(config('app.timezone')), 'state' => 'queued', 'error' => null, 'next_check_at' => now(),
                ])->save();
                $this->event($claim);
                $ids[] = $claim->id;
            }

            return $ids;
        }, 3);
        foreach ($ids as $id) {
            ProcessAllegroCommissionRefundJob::dispatch($id)->afterCommit();
        }
    }

    public function process(int $id): void
    {
        $claim = AllegroCommissionRefundClaim::query()->find($id);
        if (! $claim) {
            return;
        }
        if ($claim->state !== 'queued') {
            if (in_array($claim->state, ['submitted', 'uncertain', 'known', 'failed'], true)) {
                $this->refresh($claim);
            }

            return;
        }
        try {
            $return = OrderReturn::query()->findOrFail($claim->order_return_id);
            $context = $this->context($return);
            if (isset($context['known'][$claim->line_item_id])) {
                $this->observe($claim, $context['account'], $context['known'][$claim->line_item_id]);

                return;
            }
            $sending = DB::transaction(function () use ($claim, $context): bool {
                $account = AllegroAccount::query()->lockForUpdate()->findOrFail($claim->allegro_account_id);
                $link = AllegroOrderLink::query()->lockForUpdate()->findOrFail($context['link_id']);
                $return = OrderReturn::query()->lockForUpdate()->findOrFail($claim->order_return_id);
                $current = AllegroCommissionRefundClaim::query()->lockForUpdate()->findOrFail($claim->id);
                if ($current->state !== 'queued') {
                    return false;
                }
                $this->guard($account, $link, $return, $context);
                if ($current->expires_at->isPast() || $current->context_hash !== $context['hash']
                    || $current->account_identity !== self::accountIdentity($account) || $current->return_version !== $return->lock_version) {
                    $this->invalid('Dane wniosku zmieniły się przed wysłaniem.');
                }
                $current->update(['state' => 'sending', 'started_at' => now(), 'next_check_at' => now()->addMinutes(5)]);
                $this->event($current);

                return true;
            }, 3);
        } catch (Throwable) {
            $this->transition($id, ['queued'], 'not_sent', 'Nie wysłano: dane zmieniły się, potwierdzenie wygasło albo odczyt Allegro nie powiódł się.');

            return;
        }
        if (! $sending) {
            return;
        }
        try {
            $account = AllegroAccount::query()->findOrFail($claim->allegro_account_id);
            $raw = $this->client->createCommissionRefundClaim($account, $claim->fresh());
            if (! Str::isUuid($raw['id'] ?? '')) {
                throw new AllegroApiException('Allegro nie zwróciło identyfikatora wniosku.');
            }
            DB::transaction(function () use ($id, $raw): void {
                $current = AllegroCommissionRefundClaim::query()->lockForUpdate()->findOrFail($id);
                if ($current->external_claim_id && $current->external_claim_id !== strtolower($raw['id'])) {
                    throw new AllegroApiException('Niespójna tożsamość wniosku.');
                }
                if ($current->state === 'known') {
                    return;
                }
                $current->update(['state' => 'submitted', 'external_claim_id' => strtolower($raw['id']), 'error' => null, 'next_check_at' => now()->addMinutes(5)]);
                $this->event($current);
            }, 3);
        } catch (Throwable $e) {
            $definitive = $e instanceof AllegroApiException && in_array($e->statusCode, [400, 401, 403, 404, 406, 422], true);
            $this->transition($id, ['sending'], $definitive ? 'failed' : 'uncertain', $definitive
                ? 'Allegro odrzuciło żądanie (HTTP '.$e->statusCode.'). OMS sprawdzi, czy istnieje już wniosek dla tej pozycji.'
                : 'Nie potwierdzono wyniku wysłania. OMS sprawdzi istniejące wnioski; nie wyśle ponownie tego żądania.');
        }
    }

    public function refresh(AllegroCommissionRefundClaim $claim): void
    {
        try {
            $account = AllegroAccount::query()->findOrFail($claim->allegro_account_id);
            if ($account->environment !== $claim->environment || $account->allegro_user_id !== $claim->seller_user_id) {
                throw new AllegroApiException('Konto zmieniło się.');
            }
            $data = $claim->external_claim_id
                ? $this->normalize($this->client->commissionRefundClaim($account, $claim->external_claim_id))
                : ($this->findClaims($account, $claim->buyer_id)[$claim->line_item_id] ?? null);
            if (! $data) {
                throw new AllegroApiException('Wniosek nie jest jeszcze dostępny w odczycie Allegro.');
            }
            $this->observe($claim, $account, $data);
        } catch (Throwable) {
            AllegroCommissionRefundClaim::query()->whereKey($claim->id)->update(['error' => 'Nie udało się uzgodnić wniosku z Allegro. Zachowano poprzedni wynik.', 'next_check_at' => now()->addMinutes(5)]);
        }
    }

    public function findClaims(AllegroAccount $account, string $buyerId): array
    {
        $found = [];
        $ids = [];
        for ($offset = 0; $offset < 1000; $offset += 100) {
            $page = $this->client->commissionRefundClaims($account, ['buyer.id' => $buyerId, 'limit' => 100, 'offset' => $offset]);
            if (! is_array($page['refundClaims'] ?? null) || ! array_is_list($page['refundClaims']) || ! is_int($page['count'] ?? null)
                || $page['count'] !== count($page['refundClaims']) || $page['count'] > 100) {
                $this->invalid('Allegro zwróciło niekompletną listę wniosków o prowizję.');
            }
            foreach ($page['refundClaims'] as $raw) {
                $row = $this->normalize($raw);
                if ($row['buyer_id'] !== $buyerId || isset($ids[$row['id']]) || isset($found[$row['line_item_id']])) {
                    $this->invalid('Allegro zwróciło niespójną listę wniosków o prowizję.');
                }
                $ids[$row['id']] = true;
                $found[$row['line_item_id']] = $row;
            }
            if ($page['count'] < 100) {
                return $found;
            }
        }
        $this->invalid('Lista wniosków kupującego jest zbyt duża do bezpiecznego sprawdzenia.');
    }

    public function dispatchDue(): void
    {
        AllegroCommissionRefundClaim::query()->where('state', 'sending')->where('started_at', '<', now()->subMinutes(5))->orderBy('id')->limit(100)->get()
            ->each(fn ($claim) => $this->transition($claim->id, ['sending'], 'uncertain', 'Przerwano wysyłanie. Wymagane uzgodnienie wyniku z Allegro.'));
        AllegroCommissionRefundClaim::query()->whereIn('state', ['queued', 'submitted', 'uncertain', 'known', 'failed'])
            ->where('next_check_at', '<=', now())->orderBy('next_check_at')->limit(100)->get()
            ->each(fn ($claim) => ProcessAllegroCommissionRefundJob::dispatch($claim->id));
    }

    private function normalize(array $raw): array
    {
        $id = $raw['id'] ?? '';
        $line = data_get($raw, 'lineItem.id', '');
        $quantity = $raw['quantity'] ?? null;
        $purchased = data_get($raw, 'lineItem.quantity');
        foreach ([[$raw['status'] ?? null, 100], [$raw['type'] ?? null, 30], [data_get($raw, 'buyer.id'), 100], [data_get($raw, 'lineItem.offer.id'), 100]] as [$text, $max]) {
            if (! is_string($text) || trim($text) === '' || strlen($text) > $max) {
                $this->invalid('Niekompletne dane wniosku o prowizję.');
            }
        }
        $created = $raw['createdAt'] ?? '';
        if (! Str::isUuid($id) || ! Str::isUuid($line) || ! is_int($quantity) || ! is_int($purchased)
            || $quantity < 1 || $quantity > $purchased || $purchased > 1000000
            || ! is_string($created) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $created)) {
            $this->invalid('Niespójne dane wniosku o prowizję.');
        }
        try {
            $date = CarbonImmutable::parse($created);
        } catch (Throwable) {
            $this->invalid('Nieprawidłowa data wniosku o prowizję.');
        }
        if ($date->format('Y-m-d\TH:i:s') !== substr($created, 0, 19)) {
            $this->invalid('Nieprawidłowa data wniosku o prowizję.');
        }

        return ['id' => strtolower($id), 'line_item_id' => strtolower($line), 'quantity' => $quantity, 'purchased_quantity' => $purchased,
            'buyer_id' => data_get($raw, 'buyer.id'), 'offer_id' => data_get($raw, 'lineItem.offer.id'),
            'status' => $raw['status'], 'type' => $raw['type'], 'created_at' => $date->utc()];
    }

    private function observe(AllegroCommissionRefundClaim $claim, AllegroAccount $account, array $data): void
    {
        DB::transaction(function () use ($claim, $account, $data): void {
            $currentAccount = AllegroAccount::query()->lockForUpdate()->findOrFail($account->id);
            $current = AllegroCommissionRefundClaim::query()->lockForUpdate()->findOrFail($claim->id);
            if ($currentAccount->environment !== $current->environment || $currentAccount->allegro_user_id !== $current->seller_user_id
                || ($current->external_claim_id && $current->external_claim_id !== $data['id']) || $current->line_item_id !== $data['line_item_id']
                || $current->buyer_id !== $data['buyer_id'] || $current->offer_id !== $data['offer_id'] || $current->purchased_quantity !== $data['purchased_quantity']
                || ($current->submitted_at && ! $current->submitted_at->equalTo($data['created_at']))) {
                $this->invalid('Wniosek Allegro nie odpowiada zapisanej pozycji zamówienia.');
            }
            $changed = $current->state !== 'known' || $current->status !== $data['status'] || $current->claimed_quantity !== $data['quantity'];
            $current->update(['state' => 'known', 'external_claim_id' => $data['id'], 'claimed_quantity' => $data['quantity'],
                'status' => $data['status'], 'claim_type' => $data['type'], 'submitted_at' => $data['created_at']->setTimezone(config('app.timezone')),
                'observed_at' => now(), 'next_check_at' => now()->addMinutes(5),
                'error' => $data['quantity'] !== $current->quantity ? 'Istniejący wniosek dotyczy innej liczby sztuk. Nie wysłano kolejnego wniosku.' : null]);
            if ($changed) {
                $this->event($current);
            }
        }, 3);
    }

    private function guard(AllegroAccount $account, AllegroOrderLink $link, OrderReturn $return, array $context): void
    {
        if (! $account->isOperational() || self::accountIdentity($account) !== self::accountIdentity($context['account'])
            || $return->lock_version !== $context['return_version'] || $link->only(array_keys($context['identity'])) !== $context['identity']
            || $link->order_id !== $return->order_id || $link->replaced_by_link_id) {
            $this->invalid('Konto lub zwrot zmieniły się. Otwórz formularz ponownie.');
        }
    }

    private function transition(int $id, array $from, string $state, string $error): void
    {
        DB::transaction(function () use ($id, $from, $state, $error): void {
            $claim = AllegroCommissionRefundClaim::query()->lockForUpdate()->find($id);
            if ($claim && in_array($claim->state, $from, true)) {
                $claim->update(['state' => $state, 'error' => $error, 'next_check_at' => $state === 'not_sent' ? null : now()->addMinutes(5)]);
                $this->event($claim);
            }
        }, 3);
    }

    private function event(AllegroCommissionRefundClaim $claim): void
    {
        OrderReturn::query()->find($claim->order_return_id)?->events()->create(['type' => 'allegro_commission_claim', 'status' => mb_substr($claim->stateLabel(), 0, 100),
            'observed_at' => now(), 'data' => ['claim_id' => $claim->external_claim_id, 'state' => $claim->state,
                'status' => $claim->status, 'quantity' => $claim->claimed_quantity ?? $claim->quantity, 'product_name' => $claim->product_name]]);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['commission_refund' => $message]);
    }
}
