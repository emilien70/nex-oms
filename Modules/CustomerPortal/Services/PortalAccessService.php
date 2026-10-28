<?php

namespace Modules\CustomerPortal\Services;

use App\Exceptions\OrderEmailException;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
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

    public function bindingsReady(): bool
    {
        return $this->ready() && Schema::hasColumn('customer_portal_links', 'binding_hash');
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
        if (! $this->intact($link, $order)) {
            $data['reason'] = 'Przypisanie linku wymaga sprawdzenia przez administratora.';

            return $data;
        }
        $data['url'] = $this->urls->link($link);
        $data['reason'] = match (true) {
            ! $settings->is_enabled => 'Portal został wyłączony w ustawieniach.',
            $link->revoked_at !== null => 'Link został wyłączony.',
            ! $link->expires_at->isFuture(), ! $this->expiry($order, $settings)->isFuture() => 'Link wygasł.',
            ! $this->identityMatches($link, $order) => 'Link wymaga uzupełnienia stałego przypisania do zamówienia.',
            default => null,
        };
        $data['active'] = $data['reason'] === null;

        return $data;
    }

    public function issue(Order $order, bool $rotate = false, ?int $expectedGeneration = null, ?string $recipientEmail = null): string
    {
        if (! $this->ready() || $rotate) {
            $this->deny();
        }

        return DB::transaction(function () use ($order, $recipientEmail): string {
            $settings = $this->savedSettings();
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! $settings?->is_enabled || ! $order || ! $this->expiry($order, $settings)->isFuture()) {
                $this->deny();
            }
            if ($recipientEmail !== null && mb_strtolower(trim($recipientEmail)) !== mb_strtolower(trim((string) $order->customer_email))) {
                $this->deny();
            }
            $link = PortalLink::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if (! $link) {
                $link = $this->saveLink($order, $settings);
            }
            if (! $this->valid($link, $order, $settings)) {
                $this->deny();
            }

            return $this->urls->link($link);
        }, 3);
    }

    public function activate(Order $order, int $expectedGeneration): string
    {
        if (! $this->bindingsReady()) {
            $this->deny();
        }

        return DB::transaction(function () use ($order, $expectedGeneration): string {
            $settings = $this->savedSettings();
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! $settings->is_enabled || ! $order || ! $this->expiry($order, $settings)->isFuture()) {
                $this->deny();
            }
            $link = PortalLink::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($expectedGeneration !== ($link?->generation ?? 0)) {
                $this->deny();
            }
            if (! $link) {
                $link = $this->saveLink($order, $settings);
            } elseif (! $this->intact($link, $order) || ! $link->isBoundTo($order)) {
                $this->deny();
            } elseif ($link->revoked_at || ! $link->expires_at->isFuture()) {
                $link->update(['revoked_at' => null, 'expires_at' => $this->expiry($order, $settings), 'generation' => $link->generation + 1]);
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
                $link->update(['revoked_at' => now(), 'generation' => $link->generation + 1]);
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
            throw new OrderEmailException('portal_link_unavailable', 'Dostęp do strony klienta jest wyłączony, wygasł lub wymaga sprawdzenia w ustawieniach portalu.');
        }

        return $this->urls->link($link);
    }

    public function previewPlaceholder(): string
    {
        return $this->urls->base().'/link-przygotowywany';
    }

    public function replaceLegacyLink(int $orderId): bool
    {
        $this->deny();
    }

    public function bindExistingLink(int $linkId, bool $dryRun = false): bool
    {
        abort_unless($this->bindingsReady(), 503);

        return DB::transaction(function () use ($linkId, $dryRun): bool {
            $candidate = PortalLink::query()->find($linkId);
            if (! $candidate?->order_id) {
                return false;
            }
            $order = Order::withTrashed()->whereKey($candidate->order_id)->lockForUpdate()->first();
            $link = PortalLink::query()->whereKey($linkId)->lockForUpdate()->first();
            if (! $order || ! $link || ! $this->intact($link, $order)) {
                throw new \RuntimeException('Invalid customer portal link; binding aborted for ID '.$linkId.'.');
            }
            if ($link->binding_hash !== null) {
                return false;
            }
            if (! $dryRun) {
                $link->update(['binding_hash' => PortalLink::bindingFor($order->id, $link->token_hash), 'generation' => $link->generation + 1]);
            }

            return true;
        }, 3);
    }

    public function verificationIdentity(Order $order): string
    {
        return hash_hmac('sha256', json_encode([$order->customer_email, $order->customer_phone,
            $order->shippingAddressData(), $order->billingAddressData(),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function invalidateVerification(int $orderId): void
    {
        if ($this->bindingsReady()) {
            PortalLink::query()->where('order_id', $orderId)->whereNotNull('binding_hash')->increment('generation');
        }
    }

    private function savedSettings(): PortalSetting
    {
        $settings = PortalSetting::query()->firstOrCreate(['singleton_key' => PortalSetting::KEY]);

        return PortalSetting::query()->whereKey($settings->id)->lockForUpdate()->firstOrFail();
    }

    private function saveLink(Order $order, PortalSetting $settings): PortalLink
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = Str::random(10);
            $hash = hash('sha256', $token);
            if (PortalLink::query()->where('token_hash', $hash)->exists()) {
                continue;
            }
            try {
                // A savepoint keeps a rare unique-token collision safe on every supported driver.
                return DB::transaction(fn () => PortalLink::query()->create([
                    'order_id' => $order->id, 'token' => $token, 'token_hash' => $hash,
                    'identity_hash' => $this->identity($order), 'expires_at' => $this->expiry($order, $settings),
                    'revoked_at' => null, 'generation' => 1,
                    ...($this->bindingsReady() ? ['binding_hash' => PortalLink::bindingFor($order->id, $hash)] : []),
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
        return $this->intact($link, $order) && ! $link->revoked_at && $link->expires_at->isFuture() && $this->expiry($order, $settings)->isFuture()
            && $this->identityMatches($link, $order);
    }

    private function identityMatches(PortalLink $link, Order $order): bool
    {
        return $link->binding_hash !== null ? $link->isBoundTo($order) : hash_equals($link->identity_hash, $this->identity($order));
    }

    private function intact(PortalLink $link, Order $order): bool
    {
        try {
            return $link->order_id === $order->id && $this->shortToken($link->token)
                && hash_equals($link->token_hash, hash('sha256', $link->token))
                && ($link->binding_hash === null || $link->isBoundTo($order));
        } catch (DecryptException) {
            return false;
        }
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
