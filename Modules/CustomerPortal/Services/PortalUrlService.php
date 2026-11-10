<?php

namespace Modules\CustomerPortal\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Models\PortalSetting;

class PortalUrlService
{
    public const REQUEST_DOMAIN_ATTRIBUTE = '_customer_portal_domain';

    public static function validDomain(string $domain): bool
    {
        return strlen($domain) <= 253 && str_contains($domain, '.')
            && ! str_ends_with($domain, '.') && ! filter_var($domain, FILTER_VALIDATE_IP)
            && (bool) filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    public function domain(): ?string
    {
        $request = app()->bound('request') ? app('request') : null;
        if ($request instanceof Request && $request->attributes->has(self::REQUEST_DOMAIN_ATTRIBUTE)) {
            return $request->attributes->get(self::REQUEST_DOMAIN_ATTRIBUTE);
        }
        if (! Schema::hasTable('customer_portal_settings') || ! Schema::hasColumn('customer_portal_settings', 'customer_domain')) {
            return null;
        }
        $domain = PortalSetting::query()->where('singleton_key', PortalSetting::KEY)->value('customer_domain');
        if ($domain === null || $domain === '') {
            return null;
        }
        abort_unless(self::validDomain($domain), 503);

        return strtolower($domain);
    }

    public function base(): string
    {
        if ($domain = $this->domain()) {
            return 'https://'.$domain;
        }
        $request = app('request');
        if (app()->environment('local') && in_array(strtolower($request->getHost()), ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            return $request->getSchemeAndHttpHost();
        }
        $url = rtrim((string) config('app.url'), '/');
        abort_unless(in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) && parse_url($url, PHP_URL_HOST), 503);

        return $url;
    }

    public function route(string $name, array $parameters = []): string
    {
        return $this->base().route($name, $parameters, false);
    }

    public function link(PortalLink $link): string
    {
        return $this->route('customer-portal.show', ['orderNumber' => $link->order_id, 'token' => $link->token]);
    }

    public function information(Request $request): ?array
    {
        $profile = config('customer-portal.information');
        $localPreview = app()->environment('local') && in_array($request->getHost(), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        if (! is_array($profile) || empty($profile['host']) || (! $localPreview && $this->domain() !== $profile['host'])) {
            return null;
        }

        $base = $localPreview ? $request->getSchemeAndHttpHost() : $this->base();

        return $profile + ['url' => $base.route('customer-portal.information', [], false)];
    }

    public function accepts(Request $request): bool
    {
        return strtolower($request->getSchemeAndHttpHost()) === strtolower($this->base());
    }

    public function isCustomerLanding(Request $request): bool
    {
        return $this->domain() !== null && $request->is('/')
            && in_array($request->method(), ['GET', 'HEAD'], true) && $this->accepts($request);
    }
}
