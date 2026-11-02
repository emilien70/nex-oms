<?php

namespace Modules\Automation\Services;

use App\Models\Order;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;

class AutomationOrderSources
{
    public function options(): array
    {
        $options = [
            '*' => 'Wszystkie źródła',
            'manual' => 'Ręczne',
            'allegro' => 'Dowolne konto Allegro',
            'prestashop' => 'Dowolny sklep PrestaShop',
            'von_halsky' => 'Dowolne konto InPost Von Halsky',
        ];

        foreach (AllegroAccount::query()->orderBy('name')->get(['id', 'name']) as $account) {
            $options['allegro:'.$account->id] = $account->name.' (Allegro)';
        }
        foreach (PrestashopAccount::query()->orderBy('name')->get(['id', 'name']) as $account) {
            $options['prestashop:'.$account->id] = $account->name.' (PrestaShop)';
        }
        foreach (VonHalskyAccount::query()->orderBy('name')->get(['id', 'name']) as $account) {
            $options['von_halsky:'.$account->id] = $account->name.' (InPost Von Halsky)';
        }

        return $options;
    }

    public function identity(Order $order): array
    {
        return [
            'channel' => (string) $order->source,
            'account_id' => match ($order->source) {
                'allegro' => $order->allegroOrderLink?->allegro_account_id,
                'prestashop' => $order->prestashopOrderLinks()->oldest('id')->value('prestashop_account_id'),
                'von_halsky' => $order->vonHalskyOrderLink?->von_halsky_account_id,
                default => null,
            },
        ];
    }

    public function matches(Order $order, array $payload, array $selected): bool
    {
        $identity = $payload['order_source'] ?? $this->identity($order);
        $channel = $identity['channel'] ?? '';
        $accountId = $identity['account_id'] ?? null;

        return in_array('*', $selected, true)
            || in_array($channel, $selected, true)
            || ($accountId !== null && in_array($channel.':'.$accountId, $selected, true));
    }
}
