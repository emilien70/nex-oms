<?php

namespace Modules\Integrations\Prestashop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\Prestashop\Jobs\ImportPrestashopOrderJob;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderImportAttempt;

class PrestashopOrderImportRetryController extends Controller
{
    public function __invoke(PrestashopOrderImportAttempt $attempt): RedirectResponse
    {
        $result = DB::transaction(function () use ($attempt): array {
            $managedAttempt = PrestashopOrderImportAttempt::query()
                ->with('account')
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $account = $managedAttempt->account;

            if (
                ! $account
                || $account->account_key !== PrestashopAccount::DEFAULT_ACCOUNT_KEY
                || ! $account->is_active
                || ! $account->order_import_enabled
            ) {
                return [
                    'attempt_id' => null,
                    'message' => 'Włącz automatyczny import przed ponowieniem zamówienia.',
                    'type' => 'error',
                ];
            }

            if ($managedAttempt->status === PrestashopOrderImportAttempt::STATUS_IMPORTED) {
                return [
                    'attempt_id' => null,
                    'message' => 'To zamówienie zostało już zaimportowane.',
                    'type' => 'success',
                ];
            }

            if ($managedAttempt->status === PrestashopOrderImportAttempt::STATUS_PROCESSING) {
                return [
                    'attempt_id' => null,
                    'message' => 'Import tego zamówienia jest już w toku.',
                    'type' => 'success',
                ];
            }

            $managedAttempt->forceFill([
                'status' => PrestashopOrderImportAttempt::STATUS_PENDING,
                'last_error' => null,
            ])->save();

            return [
                'attempt_id' => $managedAttempt->getKey(),
                'message' => 'Ponowny import zamówienia PrestaShop został zlecony.',
                'type' => 'success',
            ];
        }, 3);

        if ($result['attempt_id'] === null) {
            return redirect()
                ->route('integrations.prestashop.edit', ['tab' => 'orders'])
                ->with($result['type'], $result['message']);
        }

        ImportPrestashopOrderJob::dispatch($result['attempt_id']);

        return redirect()
            ->route('integrations.prestashop.edit', ['tab' => 'orders'])
            ->with($result['type'], $result['message']);
    }
}
