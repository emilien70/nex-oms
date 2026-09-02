<?php

namespace Modules\Ksef\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Http\Requests\PreferKsefOfflineCertificateRequest;
use Modules\Ksef\Http\Requests\StoreKsefOfflineCertificateRequest;
use Modules\Ksef\Models\KsefOfflineCertificate;
use Modules\Ksef\Services\KsefOfflineCertificateRemoteVerificationService;
use Modules\Ksef\Services\KsefOfflineCertificateService;
use Throwable;

class KsefOfflineCertificateController extends Controller
{
    public function store(
        StoreKsefOfflineCertificateRequest $request,
        KsefOfflineCertificateService $certificates,
    ): RedirectResponse {
        $data = $request->validated();
        $certificateFile = $request->file('offline_certificate');
        $privateKeyFile = $request->file('offline_private_key');

        $certificates->import(
            KsefEnvironment::from($data['environment']),
            $data['label'] ?? null,
            $certificateFile->get(),
            $privateKeyFile->get(),
            $data['offline_private_key_passphrase'] ?? null,
        );

        return redirect()
            ->route('integrations.ksef.edit', ['tab' => 'offline-certificates'])
            ->with('status', 'Certyfikat Offline został zapisany lokalnie.');
    }

    public function prefer(
        PreferKsefOfflineCertificateRequest $request,
        KsefOfflineCertificate $offlineCertificate,
        KsefOfflineCertificateService $certificates,
    ): RedirectResponse {
        $certificates->setPreferred(
            $offlineCertificate,
            KsefEnvironment::from($request->validated('environment')),
        );

        return redirect()
            ->route('integrations.ksef.edit', ['tab' => 'offline-certificates'])
            ->with('status', 'Wybrano preferowany certyfikat Offline dla środowiska.');
    }

    public function destroy(
        KsefOfflineCertificate $offlineCertificate,
        KsefOfflineCertificateService $certificates,
    ): RedirectResponse {
        $certificates->delete($offlineCertificate);

        return redirect()
            ->route('integrations.ksef.edit', ['tab' => 'offline-certificates'])
            ->with('status', 'Lokalny certyfikat Offline został usunięty. Certyfikat nie został unieważniony w KSeF.');
    }

    public function verify(
        KsefOfflineCertificate $offlineCertificate,
        KsefOfflineCertificateRemoteVerificationService $verification,
    ): RedirectResponse {
        try {
            $verification->verify($offlineCertificate);
        } catch (KsefApiException $exception) {
            return redirect()
                ->route('integrations.ksef.edit', ['tab' => 'offline-certificates'])
                ->withErrors(['offline_certificate_remote' => $exception->getMessage()]);
        } catch (Throwable) {
            return redirect()
                ->route('integrations.ksef.edit', ['tab' => 'offline-certificates'])
                ->withErrors([
                    'offline_certificate_remote' => 'Nie udało się bezpiecznie sprawdzić certyfikatu Offline w KSeF.',
                ]);
        }

        return redirect()
            ->route('integrations.ksef.edit', ['tab' => 'offline-certificates'])
            ->with('status', 'Certyfikat Offline został sprawdzony w KSeF.');
    }
}
