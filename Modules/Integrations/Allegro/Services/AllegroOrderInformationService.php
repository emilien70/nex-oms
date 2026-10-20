<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Exceptions\AllegroImportException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Invoices\Services\InvoiceDecimalCalculator;

class AllegroOrderInformationService
{
    public function __construct(
        private readonly AllegroClient $client,
        private readonly InvoiceDecimalCalculator $decimal,
    ) {}

    public function snapshot(array $raw): array
    {
        $address = (array) data_get($raw, 'delivery.address', []);
        $packages = data_get($raw, 'delivery.calculatedNumberOfPackages');

        return [
            'dispatch_from' => $this->date(data_get($raw, 'delivery.time.dispatch.from')),
            'dispatch_to' => $this->date(data_get($raw, 'delivery.time.dispatch.to')),
            'delivery_from' => $this->date(data_get($raw, 'delivery.time.from')),
            'delivery_to' => $this->date(data_get($raw, 'delivery.time.to')),
            'total' => $this->money(data_get($raw, 'summary.totalToPay')),
            'delivery_cost' => $this->money(data_get($raw, 'delivery.cost')),
            'shipping_method' => $this->text(data_get($raw, 'delivery.method.name')),
            'payment_type' => $this->text(data_get($raw, 'payment.type')),
            'payment_provider' => $this->text(data_get($raw, 'payment.provider')),
            'external_status' => $this->text($raw['status'] ?? null),
            'buyer_message' => $this->text($raw['messageToSeller'] ?? null, 10000),
            'shipping_address' => implode(', ', array_filter([
                trim(($this->text($address['firstName'] ?? null) ?? '').' '.($this->text($address['lastName'] ?? null) ?? '')),
                $this->text($address['companyName'] ?? null),
                $this->text($address['street'] ?? null),
                trim(($this->text($address['zipCode'] ?? null) ?? '').' '.($this->text($address['city'] ?? null) ?? '')),
            ], fn ($value) => filled($value))),
            'invoice_required' => data_get($raw, 'invoice.required') === true,
            'smart' => data_get($raw, 'delivery.smart') === true,
            'declared_packages' => is_int($packages) && $packages > 0 ? $packages : null,
            'marketplace_id' => $this->text(data_get($raw, 'marketplace.id')),
        ];
    }

    public function refresh(Order $order): AllegroOrderLink
    {
        $link = $order->allegroOrderLink;
        if ($order->source !== 'allegro' || $link === null || $order->trashed()) {
            throw new AllegroImportException('To zamówienie nie posiada aktywnego powiązania z Allegro.');
        }
        $account = $link->account;
        $this->checkAccount($link, $account);
        $raw = $this->client->order($account, $link->external_order_id);
        if (strtolower((string) ($raw['id'] ?? '')) !== strtolower($link->external_order_id)) {
            throw new AllegroImportException('Allegro zwróciło szczegóły innego zamówienia.');
        }
        if (! is_string($raw['status'] ?? null) || ! is_array($raw['delivery'] ?? null)
            || $this->money(data_get($raw, 'summary.totalToPay')) === null) {
            throw new AllegroImportException('Allegro zwróciło niekompletne szczegóły zamówienia. Nie zmieniono zapisanych danych.');
        }
        $snapshot = $this->snapshot($raw);

        return $this->storeSnapshot($link, 'details', $snapshot);
    }

    public function refreshFees(Order $order): AllegroOrderLink
    {
        $link = $order->allegroOrderLink;
        if ($order->source !== 'allegro' || $link === null || $order->trashed()) {
            throw new AllegroImportException('To zamówienie nie posiada aktywnego powiązania z Allegro.');
        }
        $account = $link->account;
        $this->checkAccount($link, $account);
        if (! $account->hasBillingScope()) {
            throw new AllegroImportException('Brak uprawnienia do rozliczeń Allegro. Włącz odczyt rozliczeń w aplikacji Allegro i ponownie połącz integrację. Obsługa zamówień działa bez zmian.');
        }
        $marketplace = $link->details_snapshot['marketplace_id'] ?? $link->marketplace_id;
        if (blank($marketplace)) {
            throw new AllegroImportException('Najpierw odśwież szczegóły zamówienia, aby odczytać rynek Allegro.');
        }
        $entries = [];
        for ($offset = 0; $offset <= 10000; $offset += 100) {
            $previousCount = count($entries);
            $page = $this->client->billingEntries($account, $link->external_order_id, $marketplace, $offset);
            $rows = $page['billingEntries'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100) {
                throw new AllegroImportException('Nieprawidłowa odpowiedź rozliczeń Allegro.');
            }
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_string($row['id'] ?? null) || ! Str::isUuid($row['id'])
                    || strtolower((string) data_get($row, 'order.id')) !== strtolower($link->external_order_id)
                    || ($row['marketplaceId'] ?? null) !== $marketplace) {
                    throw new AllegroImportException('Allegro zwróciło opłatę bez zgodnego numeru zamówienia lub rynku.');
                }
                $id = strtolower($row['id']);
                if (isset($entries[$id]) && $entries[$id] !== $row) {
                    throw new AllegroImportException('Dane rozliczeń Allegro zmieniły się podczas pobierania. Spróbuj ponownie.');
                }
                $entries[$id] = $row;
            }
            if (count($rows) < 100) {
                return $this->storeSnapshot($link, 'fees', $this->summarizeFees($entries));
            }
            if (count($entries) === $previousCount) {
                throw new AllegroImportException('Allegro zwróciło powtórzoną stronę rozliczeń. Spróbuj ponownie.');
            }
        }

        throw new AllegroImportException('Przekroczono limit liczby opłat zamówienia. Nie zapisano niepełnych rozliczeń.');
    }

    private function summarizeFees(array $entries): array
    {
        $groups = [];
        foreach ($entries as $row) {
            $amount = data_get($row, 'value.amount');
            $currency = data_get($row, 'value.currency');
            $type = $this->text(data_get($row, 'type.id'));
            $name = $this->text(data_get($row, 'type.name'));
            $asset = $row['asset'] ?? null;
            if (! is_string($amount) || ! preg_match('/^-?\d{1,12}(?:\.\d{1,2})?$/', $amount)
                || ! is_string($currency) || ! preg_match('/^[A-Z]{3}$/', $currency)
                || $type === null || $name === null || ! in_array($asset, ['DEBIT', 'CREDIT'], true)
                || ($asset === 'DEBIT' && $this->decimal->compare($amount, '0') > 0)
                || ($asset === 'CREDIT' && $this->decimal->compare($amount, '0') < 0)) {
                throw new AllegroImportException('Nieprawidłowa kwota lub typ opłaty Allegro.');
            }
            $gross = $this->decimal->subtract('0', $amount);
            $absolute = ltrim($gross, '-');
            $rate = data_get($row, 'tax.percentage');
            $annotation = data_get($row, 'tax.annotation');
            $net = null;
            // Round each entry half-up before grouping; never infer a missing tax rate.
            if (is_string($rate) && preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $rate)
                && $this->decimal->compare($rate, '100') <= 0 && $annotation === null) {
                $net = $this->decimal->netFromGross($absolute, $rate, 2);
            } elseif ($rate === null && in_array($annotation, ['OUT_OF_SCOPE', 'NOT_APPLICABLE', 'EXEMPT'], true)) {
                $net = $absolute;
            } elseif ($rate !== null || $annotation !== null) {
                throw new AllegroImportException('Nieprawidłowa stawka podatku opłaty Allegro.');
            }
            if ($net !== null && $this->decimal->compare($gross, '0') < 0) {
                $net = $this->decimal->subtract('0', $net);
            }
            $key = $type.'|'.$currency.'|'.$asset;
            $groups[$key] ??= ['type_id' => $type, 'name' => $name, 'asset' => $asset, 'currency' => $currency, 'net' => '0.00', 'gross' => '0.00'];
            $groups[$key]['gross'] = $this->decimal->add($groups[$key]['gross'], $gross);
            $groups[$key]['net'] = $net === null || $groups[$key]['net'] === null
                ? null : $this->decimal->add($groups[$key]['net'], $net);
        }

        return ['rows' => array_values($groups), 'entry_count' => count($entries)];
    }

    private function storeSnapshot(AllegroOrderLink $link, string $section, array $snapshot): AllegroOrderLink
    {
        // Keep remote reads outside the transaction and recheck identity before saving metadata.
        return DB::transaction(function () use ($link, $section, $snapshot): AllegroOrderLink {
            $lockedAccount = AllegroAccount::query()->lockForUpdate()->find($link->allegro_account_id);
            $lockedLink = AllegroOrderLink::query()->lockForUpdate()->findOrFail($link->id);
            $this->checkAccount($lockedLink, $lockedAccount);
            if ($section === 'fees' && ! $lockedAccount->hasBillingScope()) {
                throw new AllegroImportException('Uprawnienia do rozliczeń zmieniły się. Połącz konto Allegro ponownie.');
            }
            if ($lockedLink->order_id !== $link->order_id || $lockedLink->allegro_account_id !== $link->allegro_account_id
                || $lockedLink->external_order_id !== $link->external_order_id
                || $lockedLink->environment !== $link->environment || $lockedLink->seller_user_id !== $link->seller_user_id
                || ! Order::query()->whereKey($link->order_id)->exists()) {
                throw new AllegroImportException('Powiązanie zamówienia zmieniło się. Odśwież stronę.');
            }
            $lockedLink->update([$section.'_snapshot' => $snapshot, $section.'_refreshed_at' => now()]);

            return $lockedLink;
        });
    }

    private function checkAccount(AllegroOrderLink $link, ?AllegroAccount $account): void
    {
        if ($account === null || ! $account->isOperational() || $account->environment !== $link->environment
            || $account->allegro_user_id !== $link->seller_user_id) {
            throw new AllegroImportException('Połącz właściwe konto sprzedawcy Allegro, z którego pochodzi zamówienie.');
        }
    }

    private function money(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value['amount'] ?? null)
            || ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $value['amount'])
            || ! preg_match('/^[A-Z]{3}$/', (string) ($value['currency'] ?? ''))) {
            return null;
        }

        return ['amount' => $this->decimal->normalize($value['amount'], 2), 'currency' => $value['currency']];
    }

    private function text(mixed $value, int $length = 500): ?string
    {
        return is_string($value) && filled(trim($value)) ? mb_substr(trim($value), 0, $length) : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
