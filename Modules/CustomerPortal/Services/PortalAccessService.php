<?php

namespace Modules\CustomerPortal\Services;

use App\Exceptions\OrderEmailException;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Models\PortalSetting;

class PortalAccessService
{
    public function __construct(private readonly PortalUrlService $urls) {}

    public function ready(): bool
    {
        return Schema::hasTable('customer_portal_settings') && Schema::hasTable('customer_portal_links') && Schema::hasTable('customer_portal_statuses');
    }

    public function settings(): PortalSetting
    {
        return $this->ready() ? (PortalSetting::query()->where('singleton_key', PortalSetting::KEY)->first() ?? new PortalSetting) : new PortalSetting;
    }

    public function expiry(Order $order, PortalSetting $settings): CarbonImmutable
    {
        return ($order->purchased_at ?? $order->created_at)->toImmutable()->addDays($settings->availability_days);
    }

    public function provision(int $orderId): ?PortalLink
    {
        if (! $this->ready()) {
            return null;
        }

        return DB::transaction(function () use ($orderId): ?PortalLink {
            $settings = $this->savedSettings();
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if (! $order) {
                return null;
            }
            $link = PortalLink::query()->where('order_id', $order->id)->lockForUpdate()->first();

            return $link ?? $this->saveLink($order, $settings);
        }, 3);
    }

    public function enable(): void
    {
        abort_unless($this->ready(), 503);
        DB::transaction(function (): void {
            $settings = $this->savedSettings();
            if (! $settings->is_enabled) {
                $settings->update(['is_enabled' => true, 'revision' => $settings->revision + 1]);
            }
        }, 3);
    }

    /** @return array{url: ?string, active: bool, reason: ?string} */
    public function orderLink(Order $order): array
    {
        $data = ['url' => null, 'active' => false, 'reason' => 'Link nie został jeszcze przygotowany.'];
        if (! $this->ready() || $order->trashed()) {
            return $data;
        }
        $settings = $this->settings();
        $link = PortalLink::query()->where('order_id', $order->id)->first();
        if (! $link) {
            return $data;
        }
        $data['url'] = $this->urls->link($link);
        $data['reason'] = match (true) {
            ! $settings->is_enabled => 'Portal został wyłączony w ustawieniach.',
            ! $this->shortToken($link->token) => 'Link wymaga wymiany na nowy format.',
            $link->revoked_at !== null => 'Link został wyłączony.',
            ! $link->expires_at->isFuture(), ! $this->expiry($order, $settings)->isFuture() => 'Link wygasł.',
            ! hash_equals($link->identity_hash, $this->identity($order)) => 'Link wymaga odnowienia po zmianie danych nabywcy.',
            default => null,
        };
        $data['active'] = $data['reason'] === null;

        return $data;
    }

    public function issue(Order $order, bool $rotate = false, ?int $expectedGeneration = null, ?string $recipientEmail = null): string
    {
        if (! $this->ready()) {
            $this->deny();
        }

        return DB::transaction(function () use ($order, $rotate, $expectedGeneration, $recipientEmail): string {
            $settings = $this->savedSettings();
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! $settings?->is_enabled || ! $order || ! $this->expiry($order, $settings)->isFuture()) {
                $this->deny();
            }
            if ($recipientEmail !== null && mb_strtolower(trim($recipientEmail)) !== mb_strtolower(trim((string) $order->customer_email))) {
                $this->deny();
            }
            $link = PortalLink::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($rotate && $expectedGeneration !== ($link?->generation ?? 0)) {
                $this->deny();
            }
            if (! $link || $rotate) {
                $link = $this->saveLink($order, $settings, $link);
            }
            if (! $this->valid($link, $order, $settings)) {
                $this->deny();
            }

            return $this->urls->link($link);
        }, 3);
    }

    public function revoke(Order $order, int $expectedGeneration): void
    {
        if (! $this->ready()) {
            $this->deny();
        }
        DB::transaction(function () use ($order, $expectedGeneration): void {
            PortalSetting::query()->where('singleton_key', PortalSetting::KEY)->lockForUpdate()->first();
            Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $link = PortalLink::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if (! $link || $link->generation !== $expectedGeneration) {
                $this->deny();
            }
            if (! $link->revoked_at) {
                $link->update(['revoked_at' => now()]);
            }
        }, 3);
    }

    /** @return array{order: Order, settings: PortalSetting, link: PortalLink}|null */
    public function resolve(string $token, bool $lock = false, ?int $orderNumber = null): ?array
    {
        if (! $this->shortToken($token) || ! $this->ready()) {
            return null;
        }
        $settings = $lock
            ? PortalSetting::query()->where('singleton_key', PortalSetting::KEY)->lockForUpdate()->first()
            : $this->settings();
        if (! $settings?->is_enabled) {
            return null;
        }
        $link = PortalLink::query()->where('token_hash', hash('sha256', $token))->first();
        $order = $lock
            ? Order::query()->whereKey($link?->order_id)->lockForUpdate()->first()
            : $link?->order;
        if ($lock) {
            $link = PortalLink::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
        }
        if (! $link || ! $order || $link->order_id !== $order->id
            || ($orderNumber !== null && $order->id !== $orderNumber) || ! $this->valid($link, $order, $settings)) {
            return null;
        }

        return compact('order', 'settings', 'link');
    }

    public function templateUrl(Order $order): string
    {
        $settings = $this->settings();
        if (! $this->ready() || ! $settings->is_enabled || $order->trashed() || ! $this->expiry($order, $settings)->isFuture()) {
            throw new OrderEmailException('portal_unavailable', 'Strona klienta jest wyłączona lub upłynął termin jej dostępności.');
        }
        $link = PortalLink::query()->where('order_id', $order->id)->first();
        if (! $link) {
            // A template preview is read-only; actual email preparation issues the link.
            return $this->previewPlaceholder();
        }
        if (! $this->valid($link, $order, $settings)) {
            throw new OrderEmailException('portal_link_unavailable', 'Link strony klienta wymaga świadomego wygenerowania nowego kodu na karcie zamówienia.');
        }

        return $this->urls->link($link);
    }

    public function previewPlaceholder(): string
    {
        return $this->urls->base().'/link-przygotowywany';
    }

    public function replaceLegacyLink(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId): bool {
            $settings = $this->savedSettings();
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            $link = PortalLink::query()->where('order_id', $orderId)->lockForUpdate()->first();
            if (! $settings->is_enabled || ! $order || ! $link || $this->shortToken($link->token)
                || $link->revoked_at || ! $link->expires_at->isFuture() || ! $this->expiry($order, $settings)->isFuture()
                || ! hash_equals($link->identity_hash, $this->identity($order))) {
                return false;
            }
            $expiry = $link->expires_at;
            $this->saveLink($order, $settings, $link)->update(['expires_at' => $expiry]);

            return true;
        }, 3);
    }

    private function savedSettings(): PortalSetting
    {
        $settings = PortalSetting::query()->firstOrCreate(['singleton_key' => PortalSetting::KEY]);

        return PortalSetting::query()->whereKey($settings->id)->lockForUpdate()->firstOrFail();
    }

    private function saveLink(Order $order, PortalSetting $settings, ?PortalLink $previous = null): PortalLink
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = Str::random(10);
            $hash = hash('sha256', $token);
            if (PortalLink::query()->where('token_hash', $hash)->exists()) {
                continue;
            }
            try {
                // A savepoint keeps a rare unique-token collision safe on every supported driver.
                return DB::transaction(fn () => PortalLink::query()->updateOrCreate(['order_id' => $order->id], [
                    'token' => $token, 'token_hash' => $hash,
                    'identity_hash' => $this->identity($order), 'expires_at' => $this->expiry($order, $settings),
                    'revoked_at' => null, 'generation' => ($previous?->generation ?? 0) + 1,
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                if (! PortalLink::query()->where('token_hash', $hash)->exists()) {
                    throw $exception;
                }
            }
        }
        throw new \RuntimeException('Could not allocate a unique customer portal code.');
    }

    private function valid(PortalLink $link, Order $order, PortalSetting $settings): bool
    {
        return $this->shortToken($link->token) && ! $link->revoked_at && $link->expires_at->isFuture() && $this->expiry($order, $settings)->isFuture()
            && hash_equals($link->identity_hash, $this->identity($order));
    }

    private function shortToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{10}$/D', $token) === 1;
    }

    private function identity(Order $order): string
    {
        return hash_hmac('sha256', json_encode([$order->id, $order->source, $order->external_id,
            mb_strtolower(trim((string) $order->customer_email)), $order->billing_name,
            $order->billing_company_name, $order->billing_tax_id,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function deny(): never
    {
        throw ValidationException::withMessages(['portal' => 'Strona klienta jest niedostępna. Sprawdź ustawienia, ważność i stan linku.']);
    }
}
