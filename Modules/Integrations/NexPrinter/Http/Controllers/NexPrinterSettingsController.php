<?php

namespace Modules\Integrations\NexPrinter\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Modules\Integrations\NexPrinter\Exceptions\NexPrinterException;
use Modules\Integrations\NexPrinter\Models\NexPrinter;
use Modules\Integrations\NexPrinter\Models\NexPrinterAgent;
use Modules\Integrations\NexPrinter\Models\NexPrintJob;
use Modules\Integrations\NexPrinter\Services\NexPrinterAgentRemovalService;
use Modules\Integrations\NexPrinter\Services\NexPrinterPairingService;
use Modules\Integrations\NexPrinter\Services\NexPrinterRegistry;
use Modules\Integrations\NexPrinter\Services\NexPrinterReleaseService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NexPrinterSettingsController extends Controller
{
    public function index(NexPrinterReleaseService $releases): View
    {
        $agents = NexPrinterAgent::query()
            ->with(['printers' => fn ($query) => $query
                ->orderByDesc('is_enabled')
                ->orderBy('display_name')])
            ->latest('id')
            ->get();
        $release = $releases->current();

        return view('integrations.nex-printer.index', [
            'agents' => $agents,
            'release' => $release,
            'agentVersionStates' => $release === null
                ? collect()
                : $agents->mapWithKeys(fn (NexPrinterAgent $agent): array => [
                    $agent->getKey() => $releases->clientVersionState($agent->client_version, $release),
                ]),
            'printers' => NexPrinter::query()
                ->with('agent')
                ->orderByDesc('is_enabled')
                ->orderBy('display_name')
                ->get(),
            'jobs' => NexPrintJob::query()
                ->with(['agent', 'printer'])
                ->latest('id')
                ->limit(25)
                ->get(),
        ]);
    }

    public function downloadInstaller(NexPrinterReleaseService $releases): StreamedResponse
    {
        $release = $releases->current();
        abort_unless($release !== null && $releases->checksumMatches($release), 404);

        return Storage::disk('local')->download(
            $release->storagePath(),
            $release->filename,
            [
                'Content-Type' => 'application/vnd.microsoft.portable-executable',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-NEX-Printer-SHA256' => $release->sha256,
            ],
        );
    }

    public function createPairingCode(NexPrinterPairingService $pairing): RedirectResponse
    {
        return back()
            ->with('success', 'Utworzono kod połączenia z NEX Printer.')
            ->with('nex_printer_pairing_code', $pairing->createCode());
    }

    public function updatePrinterEnabled(
        Request $request,
        NexPrinter $printer,
        NexPrinterRegistry $registry,
    ): RedirectResponse {
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
        ]);

        try {
            $registry->setEnabled($printer, (bool) $validated['is_enabled']);
        } catch (NexPrinterException $exception) {
            return back()->withErrors(['nex_printer' => $exception->getMessage()]);
        }

        return back()->with(
            'success',
            $validated['is_enabled']
                ? 'Aktywowano drukarkę NEX Printer.'
                : 'Wyłączono drukarkę NEX Printer.',
        );
    }

    public function revokeAgent(NexPrinterAgent $agent): RedirectResponse
    {
        $agent->forceFill([
            'is_active' => false,
            'revoked_at' => now(),
        ])->save();
        $agent->printers()->update([
            'is_available' => false,
            'is_default' => false,
        ]);

        return back()->with('success', 'Odłączono program NEX Printer.');
    }

    public function removeAgent(
        NexPrinterAgent $agent,
        NexPrinterAgentRemovalService $removal,
    ): RedirectResponse {
        try {
            $removal->remove($agent);
        } catch (NexPrinterException $exception) {
            return back()->withErrors(['nex_printer' => $exception->getMessage()]);
        }

        return back()->with('success', 'Usunięto komputer NEX Printer wraz z jego drukarkami.');
    }
}
