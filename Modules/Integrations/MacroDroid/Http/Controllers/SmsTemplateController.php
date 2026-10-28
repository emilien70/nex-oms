<?php

namespace Modules\Integrations\MacroDroid\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\MacroDroid\Models\SmsTemplate;
use Modules\Integrations\MacroDroid\Services\SmsPreparationService;

class SmsTemplateController extends Controller
{
    public function store(Request $request, SmsPreparationService $preparation)
    {
        $data = $request->validateWithBag('smsTemplate', ['name' => ['required', 'string', 'max:120', 'unique:sms_templates,name'],
            'body_template' => ['required', 'string', 'max:12000']]);
        try {
            $preparation->validateTemplate($data['body_template']);
        } catch (ValidationException $exception) {
            throw $exception->errorBag('smsTemplate');
        }
        SmsTemplate::query()->create($data);

        return redirect()->route('orders.email-templates.index')->with('success', 'Dodano szablon SMS.');
    }

    public function update(Request $request, SmsTemplate $smsTemplate, SmsPreparationService $preparation)
    {
        $data = $request->validateWithBag('smsTemplate', ['name' => ['required', 'string', 'max:120', 'unique:sms_templates,name,'.$smsTemplate->id],
            'body_template' => ['required', 'string', 'max:12000'], 'expected_lock_version' => ['required', 'integer', 'min:0']]);
        try {
            $preparation->validateTemplate($data['body_template']);
        } catch (ValidationException $exception) {
            throw $exception->errorBag('smsTemplate');
        }
        DB::transaction(function () use ($data, $smsTemplate): void {
            $template = SmsTemplate::query()->whereKey($smsTemplate->id)->lockForUpdate()->firstOrFail();
            abort_unless($template->lock_version === (int) $data['expected_lock_version'], 409);
            $template->update(['name' => $data['name'], 'body_template' => $data['body_template'], 'lock_version' => $template->lock_version + 1]);
        });

        return redirect()->route('orders.email-templates.index')->with('success', 'Zapisano szablon SMS.');
    }

    public function destroy(Request $request, SmsTemplate $smsTemplate)
    {
        $data = $request->validate(['expected_lock_version' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($data, $smsTemplate): void {
            $template = SmsTemplate::query()->whereKey($smsTemplate->id)->lockForUpdate()->firstOrFail();
            abort_unless($template->lock_version === (int) $data['expected_lock_version'], 409);
            $template->delete();
        });

        return redirect()->route('orders.email-templates.index')->with('success', 'Usunieto szablon SMS. Historia wiadomosci pozostala zachowana.');
    }
}
