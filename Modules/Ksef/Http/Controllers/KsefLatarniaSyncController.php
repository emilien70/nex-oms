<?php

namespace Modules\Ksef\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Services\KsefLatarniaSyncService;
use Modules\Ksef\Services\KsefSettingsService;

final class KsefLatarniaSyncController extends Controller
{
    public function __invoke(
        KsefSettingsService $settings,
        KsefLatarniaSyncService $sync,
    ): RedirectResponse {
        $environment = $settings->get()->environment;
        $latarniaEnvironment = match ($environment) {
            KsefEnvironment::Test => KsefLatarniaEnvironment::Test,
            KsefEnvironment::Production => KsefLatarniaEnvironment::Production,
            KsefEnvironment::Demo => null,
        };

        if ($latarniaEnvironment === null) {
            return $this->redirect('Latarnia niedostępna dla środowiska DEMO.', 'warning');
        }

        $result = $sync->sync($latarniaEnvironment);

        return match (true) {
            $result->statusSuccess && $result->messagesSuccess => $this->redirect('Dane Latarni KSeF zostały odświeżone.', 'status'),
            $result->statusSuccess => $this->redirect('Odświeżono status Latarni, ale nie udało się odświeżyć historii komunikatów.', 'warning'),
            $result->messagesSuccess => $this->redirect('Odświeżono historię komunikatów, ale nie udało się odświeżyć statusu Latarni.', 'warning'),
            default => $this->redirect('Nie udało się odświeżyć danych Latarni KSeF.', 'error'),
        };
    }

    private function redirect(string $message, string $key): RedirectResponse
    {
        return redirect()
            ->route('integrations.ksef.edit', ['tab' => 'latarnia'])
            ->with($key, $message);
    }
}
