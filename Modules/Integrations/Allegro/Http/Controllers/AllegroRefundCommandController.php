<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrderReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroRefundCommand;
use Modules\Integrations\Allegro\Services\AllegroRefundCommandService;
use Modules\Integrations\Allegro\Services\AllegroRefundContextService;

class AllegroRefundCommandController extends Controller
{
    public function create(Request $request, OrderReturn $orderReturn, AllegroRefundContextService $context)
    {
        try {
            $fresh = $context->fresh($orderReturn);
        } catch (AllegroApiException $e) {
            throw ValidationException::withMessages(['refund_payment' => $e->getMessage()]);
        }

        return response()->view('returns.refund-payment', ['orderReturn' => $orderReturn, 'fresh' => $fresh, 'command' => null])
            ->header('Cache-Control', 'private, no-store');
    }

    public function prepare(Request $request, OrderReturn $orderReturn, AllegroRefundCommandService $service)
    {
        $data = $request->validate([
            'context_hash' => ['required', 'string', 'size:64'], 'items' => ['required', 'array', 'max:1000'],
            'items.*' => ['array:selected,quantity,amount'], 'items.*.selected' => ['sometimes', 'boolean'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.amount' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/D'],
            'include_delivery' => ['sometimes', 'boolean'], 'delivery_amount' => ['nullable', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/D'],
            'reason' => ['required', Rule::in(array_keys(AllegroRefundCommand::REASONS))], 'seller_comment' => ['nullable', 'string', 'max:250'],
        ]);
        try {
            $command = $service->prepare($orderReturn, $data, $request->session()->getId());
        } catch (AllegroApiException $e) {
            throw ValidationException::withMessages(['refund_payment' => $e->getMessage()]);
        }

        return redirect()->route('returns.refund-payment.confirmation', [$orderReturn, $command]);
    }

    public function confirmation(Request $request, OrderReturn $orderReturn, AllegroRefundCommand $command)
    {
        $this->owns($request, $orderReturn, $command);

        return response()->view('returns.refund-payment', ['orderReturn' => $orderReturn, 'command' => $command, 'fresh' => null])
            ->header('Cache-Control', 'private, no-store');
    }

    public function confirm(Request $request, OrderReturn $orderReturn, AllegroRefundCommand $command, AllegroRefundCommandService $service)
    {
        $this->owns($request, $orderReturn, $command);
        $request->validate(['confirmed' => ['accepted']]);
        try {
            $service->confirm($command, $request->session()->getId());
        } catch (AllegroApiException $e) {
            throw ValidationException::withMessages(['refund_payment' => $e->getMessage()]);
        }

        return redirect()->route('returns.show', $orderReturn);
    }

    public function reconcile(Request $request, OrderReturn $orderReturn, AllegroRefundCommand $command, AllegroRefundCommandService $service)
    {
        abort_unless($command->order_return_id === $orderReturn->id, 404);
        $data = $request->validate(['external_refund_id' => ['required', 'uuid'], 'confirmed_identity' => ['accepted']]);
        try {
            $service->bindUncertain($command, strtolower($data['external_refund_id']));
        } catch (AllegroApiException $e) {
            throw ValidationException::withMessages(['refund_payment' => $e->getMessage()]);
        }

        return redirect()->route('returns.show', $orderReturn);
    }

    public function settings(Request $request)
    {
        $data = $request->validate(['identity' => ['required', 'string', 'max:255'], 'configuration_revision' => ['required', 'integer', 'min:0'],
            'refund_write_scope_requested' => ['required', 'boolean'], 'refund_write_enabled' => ['required', 'boolean']]);
        DB::transaction(function () use ($data): void {
            $account = AllegroAccount::query()->where('account_key', AllegroAccount::DEFAULT_ACCOUNT_KEY)->lockForUpdate()->firstOrFail();
            if ($data['identity'] !== $account->orderImportIdentity() || (int) $data['configuration_revision'] !== $account->refund_write_revision) {
                throw ValidationException::withMessages(['refund_write' => 'Konfiguracja zmieniła się. Odśwież stronę.']);
            }
            if ($data['refund_write_enabled'] && (! $data['refund_write_scope_requested'] || ! $account->isOperational()
                || array_diff([AllegroAccount::PAYMENTS_READ_SCOPE, AllegroAccount::PAYMENTS_WRITE_SCOPE], $account->granted_scopes ?? []) !== [])) {
                throw ValidationException::withMessages(['refund_write' => 'Najpierw zezwól na uprawnienie zapisu, ponownie połącz konto i wykonaj test połączenia.']);
            }
            $account->update(['refund_write_scope_requested' => (bool) $data['refund_write_scope_requested'],
                'refund_write_enabled' => (bool) $data['refund_write_enabled'], 'refund_write_identity' => $account->orderImportIdentity(),
                'refund_write_revision' => $account->refund_write_revision + 1]);
        }, 3);

        return redirect()->route('integrations.allegro.edit', ['tab' => 'returns'])->with('success', 'Ustawienia ręcznych zwrotów wpłat zapisane.');
    }

    private function owns(Request $request, OrderReturn $return, AllegroRefundCommand $command): void
    {
        abort_unless($command->order_return_id === $return->id && hash_equals($command->session_hash, hash('sha256', $request->session()->getId())), 404);
    }
}
