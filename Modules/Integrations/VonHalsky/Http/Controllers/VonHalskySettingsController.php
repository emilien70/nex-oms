<?php

namespace Modules\Integrations\VonHalsky\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrderStatusSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\VonHalsky\Enums\VonHalskyEnvironment;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyApiException;
use Modules\Integrations\VonHalsky\Http\Requests\SaveVonHalskyAutomaticImportRequest;
use Modules\Integrations\VonHalsky\Http\Requests\SaveVonHalskyImportSettingsRequest;
use Modules\Integrations\VonHalsky\Http\Requests\SaveVonHalskyStatusMappingsRequest;
use Modules\Integrations\VonHalsky\Http\Requests\UpdateVonHalskyAccountRequest;
use Modules\Integrations\VonHalsky\Http\Requests\VonHalskyAccountActionRequest;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderImportAttempt;
use Modules\Integrations\VonHalsky\Services\VonHalskyAccountService;
use Modules\Integrations\VonHalsky\Services\VonHalskyClient;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderImportDispatcher;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderImportService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderImportState;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderPreviewService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderStatusSyncService;
use Modules\Integrations\VonHalsky\Services\VonHalskyStatusMappingService;

class VonHalskySettingsController extends Controller
{
    public function __construct(private readonly VonHalskyAccountService $accounts) {}

    public function edit(Request $request): Response
    {
        return $this->page(in_array($request->query('tab'), ['orders', 'statuses'], true) ? $request->query('tab') : 'connection');
    }

    public function update(UpdateVonHalskyAccountRequest $request): RedirectResponse
    {
        $this->accounts->update($request->validated());

        return redirect()->route('integrations.von-halsky.edit')->with('success', 'Ustawienia Von Halsky zostały zapisane.');
    }

    public function testConnection(VonHalskyAccountActionRequest $request, VonHalskyClient $client): RedirectResponse
    {
        $account = $this->accounts->beginTest((int) $request->validated('expected_lock_version'));

        try {
            $client->testConnection($account);
            $this->accounts->recordTest($account);
        } catch (VonHalskyApiException $exception) {
            try {
                $this->accounts->recordTest($account, $exception->getMessage());
            } catch (VonHalskyApiException $changed) {
                return redirect()->route('integrations.von-halsky.edit')->with('error', $changed->getMessage());
            }

            return redirect()->route('integrations.von-halsky.edit')->with('error', $this->message($exception));
        }

        return redirect()->route('integrations.von-halsky.edit')->with('success', 'Połączenie Von Halsky zostało sprawdzone.');
    }

    public function disconnect(VonHalskyAccountActionRequest $request): RedirectResponse
    {
        $this->accounts->disconnect((int) $request->validated('expected_lock_version'));

        return redirect()->route('integrations.von-halsky.edit')->with('success', 'Połączenie w OMS zostało wyłączone.');
    }

    public function preview(VonHalskyAccountActionRequest $request, VonHalskyOrderPreviewService $previews): Response
    {
        $account = $this->accounts->get();
        if ($account->lock_version !== (int) $request->validated('expected_lock_version')) {
            throw ValidationException::withMessages(['expected_lock_version' => 'Ustawienia zostały zmienione. Odśwież stronę.']);
        }
        $id = $request->validated('order_identifier');

        try {
            $preview = $previews->preview($account, $id);
        } catch (VonHalskyApiException $exception) {
            $request->session()->forget('von_halsky_order_preview');

            return $this->page('orders', $id, error: $this->message($exception));
        }

        $request->session()->forget('von_halsky_order_preview');
        if ($preview['import']['source_hash'] !== null) {
            $request->session()->put('von_halsky_order_preview', [
                'account_id' => $account->id, 'identifier' => $id, 'lock_version' => $account->lock_version,
                'connection_revision' => $account->connection_revision, 'hash' => $preview['import']['source_hash'],
                'expires_at' => now()->addMinutes(10)->timestamp,
            ]);
        }

        return $this->page('orders', $id, $preview);
    }

    public function updateImportSettings(SaveVonHalskyImportSettingsRequest $request): RedirectResponse
    {
        $this->accounts->updateImportSettings($request->validated());
        $request->session()->forget('von_halsky_order_preview');

        return redirect()->route('integrations.von-halsky.edit', ['tab' => 'orders'])->with('success', 'Ustawienia importu Von Halsky zostały zapisane.');
    }

    private function page(string $tab, ?string $id = null, ?array $preview = null, ?string $error = null): Response
    {
        $account = $this->accounts->get();
        $discoveryReady = app(VonHalskyOrderImportState::class)->ready();
        $mappings = app(VonHalskyStatusMappingService::class);
        $statusMappingsReady = $mappings->ready();
        $history = $discoveryReady && $account->exists ? VonHalskyOrderImportAttempt::query()
            ->where('von_halsky_account_id', $account->id)->where('environment', $account->environment->value)
            ->where('organization_id', $account->organization_id) : null;
        $recentImports = $history ? (clone $history)->with('order')->orderByDesc('external_created_at')->orderByDesc('id')->limit(10)->get() : collect();
        $attentionImports = $history && $account->order_import_started_at ? (clone $history)
            ->where('external_created_at', '>=', $account->order_import_started_at)
            ->whereIn('status', VonHalskyOrderImportAttempt::ATTENTION)->with('order')
            ->orderBy('discovered_at')->orderBy('id')->paginate(20, ['*'], 'attention_page')
            ->withPath(route('integrations.von-halsky.edit'))->withQueryString()->appends(['tab' => 'orders']) : null;

        return response()->view('integrations.von-halsky.edit', [
            'account' => $account,
            'discoveryReady' => $discoveryReady,
            'statusMappingsReady' => $statusMappingsReady,
            'statusMappings' => $statusMappingsReady && $account->exists ? $mappings->query($account)->get()->pluck('action', 'order_status_setting_id') : collect(),
            'canCheckKnownStatuses' => app(VonHalskyOrderStatusSyncService::class)->ready()
                && app(VonHalskyOrderStatusSyncService::class)->hasKnownOrders($account),
            'recentImports' => $recentImports,
            'attentionImports' => $attentionImports,
            'environments' => VonHalskyEnvironment::cases(),
            'activeTab' => $tab,
            'orderIdentifier' => $id,
            'orderPreview' => $preview,
            'previewError' => $error,
            'orderStatuses' => OrderStatusSetting::orderedSettings(),
            'existingLink' => $id && $this->accounts->get()->exists
                ? app(VonHalskyOrderImportService::class)->link($this->accounts->get(), $id) : null,
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function updateAutomaticImport(SaveVonHalskyAutomaticImportRequest $request): RedirectResponse
    {
        $this->accounts->updateAutomaticImport($request->validated());
        $request->session()->forget('von_halsky_order_preview');

        return redirect()->route('integrations.von-halsky.edit', ['tab' => 'orders'])->with('success', 'Ustawienia pobierania Von Halsky zostały zapisane.');
    }

    public function checkNow(VonHalskyAccountActionRequest $request, VonHalskyOrderImportDispatcher $dispatcher): RedirectResponse
    {
        $dispatcher->requestNow($this->accounts->get(), (int) $request->validated('expected_lock_version'));

        return redirect()->route('integrations.von-halsky.edit', ['tab' => 'orders'])->with('success', 'Zlecono sprawdzenie zamówień Von Halsky.');
    }

    public function updateStatusMappings(SaveVonHalskyStatusMappingsRequest $request, VonHalskyStatusMappingService $mappings): RedirectResponse
    {
        $mappings->update($request->validated());

        return redirect()->route('integrations.von-halsky.edit', ['tab' => 'statuses'])->with('success', 'Powiązania statusów Von Halsky zostały zapisane.');
    }

    private function message(VonHalskyApiException $exception): string
    {
        return $exception->getMessage().($exception->retryAfter ? ' Spróbuj za '.$exception->retryAfter.' s.' : '');
    }
}
