<?php

namespace App\Http\Controllers;

use App\Enums\EmailTemplateAttachmentType;
use App\Enums\EmailTemplateDocumentType;
use App\Enums\EmailTemplateFormat;
use App\Http\Requests\SaveEmailTemplateRequest;
use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Services\EmailTemplateManagementService;
use App\Services\OrderVariableService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EmailTemplateController extends Controller
{
    public function __construct(
        private readonly EmailTemplateManagementService $templates,
    ) {}

    public function index(OrderVariableService $variables): View
    {
        $templates = EmailTemplate::query()
            ->with(['emailAccount', 'attachments'])
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        $accounts = EmailAccount::query()
            ->orderBy('email')
            ->orderBy('name')
            ->get();

        return view('orders.email-settings', [
            'activeTab' => 'templates',
            'templates' => $templates,
            'accounts' => $accounts,
            'formatOptions' => EmailTemplateFormat::cases(),
            'attachmentTypeOptions' => EmailTemplateAttachmentType::cases(),
            'documentTypeOptions' => EmailTemplateDocumentType::cases(),
            'variableGroups' => collect($variables->definitions())->groupBy('group'),
            'templateFormData' => $templates->getCollection()->mapWithKeys(
                function (EmailTemplate $template): array {
                    $attachments = $template->attachments->keyBy('slot');

                    return [(string) $template->getKey() => [
                        'id' => $template->getKey(),
                        'email_account_id' => $template->email_account_id,
                        'name' => $template->name,
                        'subject_template' => $template->subject_template,
                        'format' => $template->format->value,
                        'body_template' => $template->body_template,
                        'is_hidden' => $template->is_hidden,
                        'attachment_1_type' => $attachments->get(1)?->type->value
                            ?? EmailTemplateAttachmentType::None->value,
                        'attachment_1_document_type' => $attachments->get(1)?->document_type?->value,
                        'attachment_1_original_name' => $attachments->get(1)?->original_name,
                        'attachment_2_type' => $attachments->get(2)?->type->value
                            ?? EmailTemplateAttachmentType::None->value,
                        'attachment_2_document_type' => $attachments->get(2)?->document_type?->value,
                        'attachment_2_original_name' => $attachments->get(2)?->original_name,
                    ]];
                },
            ),
        ]);
    }

    public function store(SaveEmailTemplateRequest $request): RedirectResponse
    {
        $this->templates->create($request->templateData(), $request->attachmentData());

        return redirect()
            ->route('orders.email-templates.index')
            ->with('success', 'Szablon e-mail został zapisany.');
    }

    public function update(
        SaveEmailTemplateRequest $request,
        EmailTemplate $emailTemplate,
    ): RedirectResponse {
        $this->templates->update($emailTemplate, $request->templateData(), $request->attachmentData());

        return redirect()
            ->route('orders.email-templates.index')
            ->with('success', 'Szablon e-mail został zapisany.');
    }

    public function duplicate(EmailTemplate $emailTemplate): RedirectResponse
    {
        $copy = $this->templates->duplicate($emailTemplate);

        return redirect()
            ->route('orders.email-templates.index', ['edit' => $copy->getKey()])
            ->with('success', 'Utworzono kopię szablonu e-mail.');
    }

    public function destroy(EmailTemplate $emailTemplate): RedirectResponse
    {
        $this->templates->delete($emailTemplate);

        return redirect()
            ->route('orders.email-templates.index')
            ->with('success', 'Szablon e-mail został usunięty.');
    }
}
