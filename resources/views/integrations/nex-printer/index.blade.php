@extends('layouts.app')

@section('title', 'NEX Printer - NEX-OMS')

@section('content')
    <style>
        .nex-printer-page {
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 24px;
        }

        .nex-printer-header,
        .nex-printer-section-header,
        .nex-printer-row,
        .nex-printer-code {
            align-items: center;
            display: flex;
        }

        .nex-printer-header {
            gap: 16px;
            justify-content: space-between;
            margin-bottom: 18px;
        }

        .nex-printer-title {
            color: #111827;
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .nex-printer-subtitle {
            color: #667085;
            font-size: 13px;
            margin: 4px 0 0;
        }

        .nex-printer-section {
            background: #fff;
            border: 1px solid #d5dbe3;
            border-radius: 6px;
            margin-bottom: 16px;
            overflow: hidden;
        }

        .nex-printer-section-header {
            border-bottom: 1px solid #e2e6eb;
            justify-content: space-between;
            min-height: 52px;
            padding: 12px 16px;
        }

        .nex-printer-section-title {
            color: #20262e;
            font-size: 15px;
            font-weight: 700;
            margin: 0;
        }

        .nex-printer-release-body {
            align-items: center;
            display: flex;
            gap: 16px;
            justify-content: space-between;
            padding: 16px;
        }

        .nex-printer-release-version {
            color: #20262e;
            font-size: 14px;
            font-weight: 700;
        }

        .nex-printer-release-details {
            color: #667085;
            font-size: 12px;
            margin-top: 3px;
        }

        .nex-printer-code {
            background: #eef7ff;
            border: 1px solid #b6d8ff;
            border-radius: 6px;
            gap: 16px;
            justify-content: space-between;
            margin-bottom: 16px;
            padding: 14px 16px;
        }

        .nex-printer-code-value {
            color: #0a58ca;
            font-family: Consolas, monospace;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0;
        }

        .nex-printer-table {
            margin: 0;
        }

        .nex-printer-table th {
            background: #f8fafc;
            color: #52606d;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .nex-printer-table td {
            color: #344054;
            font-size: 13px;
            vertical-align: middle;
        }

        .nex-printer-empty {
            color: #667085;
            font-size: 13px;
            margin: 0;
            padding: 20px 16px;
        }

        .nex-printer-status {
            border-radius: 999px;
            display: inline-flex;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
        }

        .nex-printer-status-online,
        .nex-printer-status-spooled {
            background: #e8f7ee;
            color: #11723b;
        }

        .nex-printer-status-offline,
        .nex-printer-status-failed {
            background: #fff0f0;
            color: #b42318;
        }

        .nex-printer-status-queued,
        .nex-printer-status-claimed,
        .nex-printer-status-downloaded {
            background: #eef4ff;
            color: #175cd3;
        }

        .nex-printer-status-current {
            background: #e8f7ee;
            color: #11723b;
        }

        .nex-printer-status-outdated {
            background: #fff7e6;
            color: #8a5b00;
        }

        .nex-printer-status-preview {
            background: #eef4ff;
            color: #175cd3;
        }

        .nex-printer-status-unknown {
            background: #f2f4f7;
            color: #667085;
        }

        .nex-printer-default {
            color: #137a46;
            font-size: 12px;
            font-weight: 700;
        }

        .nex-printer-toggle-cell .form-check {
            align-items: center;
            display: inline-flex;
            margin: 0;
            min-height: 0;
        }

        .nex-printer-toggle-cell .form-check-input {
            cursor: pointer;
            margin: 0;
        }

        .nex-printer-toggle-cell .form-check-input:disabled {
            cursor: not-allowed;
        }

        @media (max-width: 767.98px) {
            .nex-printer-page {
                margin: -1rem;
                padding: 16px;
            }

            .nex-printer-header,
            .nex-printer-code,
            .nex-printer-release-body {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>

    <div class="nex-printer-page">
        <header class="nex-printer-header">
            <div>
                <h1 class="nex-printer-title">NEX Printer</h1>
                <p class="nex-printer-subtitle">Drukowanie dokumentów PDF na drukarkach podłączonych do lokalnego komputera.</p>
            </div>
            <form method="POST" action="{{ route('integrations.nex-printer.pairing-codes.store') }}">
                @csrf
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>
                    Połącz program
                </button>
            </form>
        </header>

        @if (session('nex_printer_pairing_code'))
            @php($pairingCode = session('nex_printer_pairing_code'))
            <div class="nex-printer-code" role="status">
                <div>
                    <div class="small fw-semibold text-secondary">Kod połączenia</div>
                    <div class="nex-printer-code-value">{{ $pairingCode['code'] }}</div>
                </div>
                <div class="small text-secondary">
                    Ważny do {{ $pairingCode['expires_at']->format('H:i:s') }}
                </div>
            </div>
        @endif

        <section class="nex-printer-section">
            <div class="nex-printer-section-header">
                <h2 class="nex-printer-section-title">Wersja programu</h2>
            </div>
            @if ($release)
                <div class="nex-printer-release-body">
                    <div>
                        <div class="nex-printer-release-version">NEX Printer {{ $release->version }}</div>
                        <div class="nex-printer-release-details">
                            {{ number_format($release->size / 1048576, 1, ',', ' ') }} MB
                            <span aria-hidden="true">·</span>
                            SHA-256: <span title="{{ $release->sha256 }}">{{ substr($release->sha256, 0, 12) }}…</span>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('integrations.nex-printer.release.download') }}">
                        @csrf
                        <button class="btn btn-outline-primary" type="submit">
                            <i class="bi bi-download me-1" aria-hidden="true"></i>
                            Pobierz instalator
                        </button>
                    </form>
                </div>
            @else
                <p class="nex-printer-empty">Brak opublikowanego instalatora NEX Printer.</p>
            @endif
        </section>

        <section class="nex-printer-section">
            <div class="nex-printer-section-header">
                <h2 class="nex-printer-section-title">Połączone programy</h2>
                <span class="small text-secondary">{{ $agents->where('is_active', true)->count() }} aktywnych</span>
            </div>
            @if ($agents->isEmpty())
                <p class="nex-printer-empty">Brak połączonych programów NEX Printer.</p>
            @else
                <div class="table-responsive">
                    <table class="table nex-printer-table">
                        <thead>
                            <tr>
                                <th>Nazwa</th>
                                <th>System</th>
                                <th>Wersja</th>
                                <th>Ostatnie połączenie</th>
                                <th>Status</th>
                                <th class="text-end">Akcje</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($agents as $agent)
                                @php($agentOnline = $agent->is_active && $agent->revoked_at === null && $agent->last_seen_at?->greaterThan(now()->subMinutes(2)))
                                @php($versionState = $agentVersionStates->get($agent->getKey()))
                                <tr>
                                    <td class="fw-semibold">{{ $agent->name }}</td>
                                    <td>{{ ucfirst($agent->platform) }}</td>
                                    <td>
                                        <span>{{ $agent->client_version ?: '—' }}</span>
                                        @if ($versionState)
                                            <span class="nex-printer-status nex-printer-status-{{ $versionState->value }} ms-1">
                                                {{ $versionState->label() }}
                                            </span>
                                        @endif
                                    </td>
                                    <td>{{ $agent->last_seen_at?->format('d.m.Y H:i:s') ?: '—' }}</td>
                                    <td>
                                        <span class="nex-printer-status {{ $agentOnline ? 'nex-printer-status-online' : 'nex-printer-status-offline' }}">
                                            {{ $agent->is_active ? ($agentOnline ? 'Online' : 'Offline') : 'Odłączony' }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @if ($agent->is_active)
                                            <form method="POST" action="{{ route('integrations.nex-printer.agents.destroy', $agent) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Odłącz program NEX Printer">
                                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                                    Odłącz
                                                </button>
                                            </form>
                                        @else
                                            <form
                                                method="POST"
                                                action="{{ route('integrations.nex-printer.agents.remove', $agent) }}"
                                                onsubmit="return confirm('Czy na pewno usunąć ten komputer NEX Printer wraz ze wszystkimi powiązanymi drukarkami?')"
                                            >
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Usuń komputer NEX Printer">
                                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                                    Usuń
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="nex-printer-section">
            <div class="nex-printer-section-header">
                <h2 class="nex-printer-section-title">Drukarki</h2>
                <span class="small text-secondary">
                    {{ $printers->where('is_enabled', true)->count() }} aktywnych
                    / {{ $printers->where('is_available', true)->count() }} dostępnych
                </span>
            </div>
            @if ($printers->isEmpty())
                <p class="nex-printer-empty">Program NEX Printer nie przekazał jeszcze listy drukarek.</p>
            @else
                <div class="table-responsive">
                    <table class="table nex-printer-table">
                        <thead>
                            <tr>
                                <th>Drukarka</th>
                                <th>Komputer</th>
                                <th>Aktywna</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($printers as $printer)
                                <tr>
                                    <td class="fw-semibold">{{ $printer->display_name }}</td>
                                    <td>{{ $printer->agent->name }}</td>
                                    <td class="nex-printer-toggle-cell">
                                        <form method="POST" action="{{ route('integrations.nex-printer.printers.enabled', $printer) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="is_enabled" value="0">
                                            <div class="form-check form-switch">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    name="is_enabled"
                                                    value="1"
                                                    role="switch"
                                                    aria-label="{{ $printer->is_enabled ? 'Wyłącz drukarkę '.$printer->display_name : 'Aktywuj drukarkę '.$printer->display_name }}"
                                                    title="{{ $printer->is_enabled ? 'Wyłącz drukarkę' : 'Aktywuj drukarkę' }}"
                                                    @checked($printer->is_enabled)
                                                    @disabled(! $printer->agent->is_active || $printer->agent->revoked_at !== null)
                                                    onchange="this.form.submit()"
                                                >
                                            </div>
                                        </form>
                                    </td>
                                    <td>
                                        <span class="nex-printer-status {{ $printer->is_available ? 'nex-printer-status-online' : 'nex-printer-status-offline' }}">
                                            {{ $printer->is_available ? 'Dostępna' : 'Niedostępna' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="nex-printer-section">
            <div class="nex-printer-section-header">
                <h2 class="nex-printer-section-title">Ostatnie zadania</h2>
                <span class="small text-secondary">25 ostatnich</span>
            </div>
            @if ($jobs->isEmpty())
                <p class="nex-printer-empty">Nie zlecono jeszcze żadnego wydruku.</p>
            @else
                <div class="table-responsive">
                    <table class="table nex-printer-table">
                        <thead>
                            <tr>
                                <th>Dokument</th>
                                <th>Drukarka</th>
                                <th>Utworzono</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($jobs as $job)
                                <tr>
                                    <td class="fw-semibold">{{ $job->document_number ?: 'Dokument' }}</td>
                                    <td>{{ $job->printer_name_snapshot }}</td>
                                    <td>{{ $job->created_at->format('d.m.Y H:i:s') }}</td>
                                    <td>
                                        <span class="nex-printer-status nex-printer-status-{{ $job->status->value }}">
                                            {{ $job->status->label() }}
                                        </span>
                                        @if ($job->last_error_message)
                                            <div class="small text-danger mt-1">{{ $job->last_error_message }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
