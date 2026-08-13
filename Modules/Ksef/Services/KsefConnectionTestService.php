<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Facades\DB;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefConnectionTestStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefCredential;

class KsefConnectionTestService
{
    public function __construct(
        private readonly KsefSettingsService $settings,
        private readonly KsefAuthenticationService $authentication,
        private readonly KsefHttpClient $http,
        private readonly KsefCurrentTokenResolver $currentTokenResolver,
        private readonly KsefCertificateOwnerResolver $certificateOwnerResolver,
    ) {}

    public function test(): void
    {
        $settings = $this->settings->get();
        $credential = KsefCredential::query()->firstOrCreate(
            ['environment' => $settings->environment->value],
            ['authentication_method' => KsefAuthenticationMethod::Token],
        );

        if (! $this->hasConfiguredAuthentication($credential)) {
            $this->record(
                $credential,
                KsefConnectionTestStatus::Error,
                $credential->authentication_method === KsefAuthenticationMethod::Certificate
                    ? 'Najpierw zapisz certyfikat KSeF i klucz prywatny dla wybranego środowiska.'
                    : 'Najpierw zapisz Token KSeF dla wybranego środowiska.',
            );

            return;
        }

        if (! is_string($settings->context_nip) || preg_match('/^\d{10}$/', $settings->context_nip) !== 1) {
            $this->record(
                $credential,
                KsefConnectionTestStatus::Error,
                'Najpierw zapisz prawidłowy NIP kontekstu KSeF.',
            );

            return;
        }

        try {
            $pair = $this->authentication->authenticate($credential, $settings->context_nip);
        } catch (KsefApiException $exception) {
            if ($exception->isCredentialOrContextFailure()) {
                $this->clearRuntimeAuthentication($credential);
            }

            $secrets = $this->knownSecrets($credential);
            $this->record(
                $credential,
                KsefConnectionTestStatus::Error,
                $this->sanitize($exception->getMessage(), $secrets),
                systemWarning: $this->sanitizeNullable($exception->systemWarning, $secrets),
            );

            return;
        }

        $warnings = $pair->systemWarnings;

        try {
            $permissionResponse = $this->http->post(
                $settings->environment,
                '/permissions/query/personal/grants',
                [
                    'permissionTypes' => ['InvoiceWrite'],
                    'permissionState' => 'Active',
                ],
                $pair->accessToken,
                [
                    'pageOffset' => 0,
                    'pageSize' => 100,
                ],
            );
            $this->addWarning($warnings, $permissionResponse->systemWarning);
        } catch (KsefApiException $exception) {
            $this->addWarning($warnings, $exception->systemWarning);
            $this->record(
                $credential,
                KsefConnectionTestStatus::Error,
                'Uwierzytelnienie zakończyło się poprawnie, ale nie udało się sprawdzić uprawnień.',
                systemWarning: $this->joinedWarnings(
                    $warnings,
                    $this->knownSecrets($credential, $pair->accessToken, $pair->refreshToken),
                ),
            );

            return;
        }

        $permissions = $permissionResponse->data['permissions'] ?? null;

        if (! is_array($permissions)) {
            $this->record(
                $credential,
                KsefConnectionTestStatus::Error,
                'Uwierzytelnienie zakończyło się poprawnie, ale KSeF zwrócił nieprawidłowe dane uprawnień.',
                systemWarning: $this->joinedWarnings(
                    $warnings,
                    $this->knownSecrets($credential, $pair->accessToken, $pair->refreshToken),
                ),
            );

            return;
        }

        $invoiceWrite = collect($permissions)->contains(
            fn (mixed $permission): bool => is_array($permission)
                && ($permission['permissionScope'] ?? null) === 'InvoiceWrite',
        );

        if (! $invoiceWrite) {
            $resolved = $credential->authentication_method === KsefAuthenticationMethod::Token
                ? $this->resolveTokenInvoiceWrite(
                    $credential,
                    $settings->context_nip,
                    $pair->accessToken,
                    $pair->refreshToken,
                    $warnings,
                )
                : $this->resolveCertificateInvoiceWrite(
                    $credential,
                    $settings->context_nip,
                    $pair->accessToken,
                    $pair->refreshToken,
                    $warnings,
                );

            if (! $resolved) {
                return;
            }

            $invoiceWrite = true;
        }

        $systemWarning = $this->joinedWarnings(
            $warnings,
            $this->knownSecrets($credential, $pair->accessToken, $pair->refreshToken),
        );

        if ($systemWarning !== null) {
            $this->record(
                $credential,
                KsefConnectionTestStatus::Warning,
                'Połączenie z KSeF działa poprawnie, ale system zwrócił ostrzeżenie techniczne.',
                true,
                $systemWarning,
            );

            return;
        }

        $this->record(
            $credential,
            KsefConnectionTestStatus::Success,
            'Połączenie z KSeF działa poprawnie.',
            true,
        );
    }

    private function hasConfiguredAuthentication(KsefCredential $credential): bool
    {
        if ($credential->authentication_method === KsefAuthenticationMethod::Certificate) {
            return is_string($credential->authentication_certificate)
                && $credential->authentication_certificate !== ''
                && is_string($credential->authentication_private_key)
                && $credential->authentication_private_key !== '';
        }

        return is_string($credential->api_token) && $credential->api_token !== '';
    }

    private function resolveTokenInvoiceWrite(
        KsefCredential $credential,
        string $contextNip,
        string $accessToken,
        string $refreshToken,
        array &$warnings,
    ): bool {
        $resolution = $this->currentTokenResolver->resolve($credential->environment, $accessToken);
        $this->addWarning($warnings, $resolution->systemWarning);
        $systemWarning = $this->joinedWarnings(
            $warnings,
            $this->knownSecrets($credential, $accessToken, $refreshToken),
        );

        if (! $resolution->isResolved()) {
            $this->record(
                $credential,
                KsefConnectionTestStatus::Warning,
                'Uwierzytelnienie w KSeF działa, ale nie udało się jednoznacznie potwierdzić uprawnienia InvoiceWrite.',
                null,
                $systemWarning,
            );

            return false;
        }

        if (! $resolution->token->requestsPermission('InvoiceWrite')) {
            $this->record(
                $credential,
                KsefConnectionTestStatus::Warning,
                'Uwierzytelnienie w KSeF działa, ale bieżący Token KSeF nie posiada uprawnienia InvoiceWrite.',
                false,
                $systemWarning,
            );

            return false;
        }

        if (! $resolution->token->isStrictNipOwner($contextNip)) {
            $this->record(
                $credential,
                KsefConnectionTestStatus::Warning,
                'Uwierzytelnienie w KSeF działa i Token posiada InvoiceWrite, ale nie wykryto aktywnego uprawnienia InvoiceWrite w bieżącym kontekście.',
                false,
                $systemWarning,
            );

            return false;
        }

        return true;
    }

    private function resolveCertificateInvoiceWrite(
        KsefCredential $credential,
        string $contextNip,
        string $accessToken,
        string $refreshToken,
        array &$warnings,
    ): bool {
        $certificate = $credential->authentication_certificate;
        $owner = is_string($certificate)
            ? $this->certificateOwnerResolver->isStrictNipOwner($certificate, $contextNip)
            : null;
        $systemWarning = $this->joinedWarnings(
            $warnings,
            $this->knownSecrets($credential, $accessToken, $refreshToken),
        );

        if ($owner === true) {
            return true;
        }

        $this->record(
            $credential,
            KsefConnectionTestStatus::Warning,
            $owner === false
                ? 'Uwierzytelnienie certyfikatem KSeF działa, ale nie wykryto aktywnego uprawnienia InvoiceWrite w bieżącym kontekście.'
                : 'Uwierzytelnienie certyfikatem KSeF działa, ale nie udało się jednoznacznie potwierdzić uprawnienia InvoiceWrite.',
            $owner === false ? false : null,
            $systemWarning,
        );

        return false;
    }

    private function record(
        KsefCredential $credential,
        KsefConnectionTestStatus $status,
        string $message,
        ?bool $invoiceWrite = null,
        ?string $systemWarning = null,
    ): void {
        DB::transaction(function () use ($credential, $status, $message, $invoiceWrite, $systemWarning): void {
            KsefCredential::query()
                ->whereKey($credential->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->forceFill([
                    'last_tested_at' => now(),
                    'last_test_status' => $status,
                    'last_test_message' => mb_substr($message, 0, 2000),
                    'last_test_invoice_write' => $invoiceWrite,
                    'last_system_warning' => $systemWarning === null
                        ? null
                        : mb_substr($systemWarning, 0, 4000),
                ])->save();
        });
    }

    private function clearRuntimeAuthentication(KsefCredential $credential): void
    {
        KsefCredential::query()
            ->whereKey($credential->getKey())
            ->update([
                'access_token' => null,
                'access_token_valid_until' => null,
                'refresh_token' => null,
                'refresh_token_valid_until' => null,
            ]);
    }

    private function addWarning(array &$warnings, ?string $warning): void
    {
        if ($warning !== null && ! in_array($warning, $warnings, true)) {
            $warnings[] = $warning;
        }
    }

    private function joinedWarnings(array $warnings, array $secrets): ?string
    {
        $safeWarnings = collect($warnings)
            ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
            ->map(fn (string $warning): string => $this->sanitize($warning, $secrets))
            ->unique()
            ->values();

        return $safeWarnings->isEmpty() ? null : $safeWarnings->implode(' | ');
    }

    private function knownSecrets(
        KsefCredential $credential,
        ?string $accessToken = null,
        ?string $refreshToken = null,
    ): array {
        return array_values(array_filter([
            is_string($credential->api_token) ? $credential->api_token : null,
            is_string($credential->authentication_private_key) ? $credential->authentication_private_key : null,
            is_string($credential->access_token) ? $credential->access_token : null,
            is_string($credential->refresh_token) ? $credential->refresh_token : null,
            $accessToken,
            $refreshToken,
        ], fn (mixed $secret): bool => is_string($secret) && $secret !== ''));
    }

    private function sanitizeNullable(?string $text, array $secrets): ?string
    {
        return $text === null ? null : $this->sanitize($text, $secrets);
    }

    private function sanitize(string $text, array $secrets): string
    {
        return trim(str_replace($secrets, '[ukryto]', $text));
    }
}
