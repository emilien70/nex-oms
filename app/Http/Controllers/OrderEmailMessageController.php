<?php

namespace App\Http\Controllers;

use App\Enums\EmailTemplateAttachmentType;
use App\Enums\EmailTemplateFormat;
use App\Enums\OrderEmailMessageSource;
use App\Exceptions\OrderEmailException;
use App\Http\Requests\StoreOrderEmailMessageRequest;
use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderEmailMessage;
use App\Models\OrderEmailMessageAttachment;
use App\Services\OrderEmailPreparationService;
use App\Services\OrderEmailTemplateRenderer;
use App\ValueObjects\OrderEmailPreparationData;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderEmailMessageController extends Controller
{
    public function preview(
        Order $order,
        EmailTemplate $emailTemplate,
        OrderEmailTemplateRenderer $renderer,
    ): JsonResponse {
        abort_if($emailTemplate->is_hidden, 404);

        $emailTemplate->loadMissing('attachments');

        try {
            $rendered = $renderer->render(
                $order,
                $emailTemplate->subject_template,
                $emailTemplate->body_template,
                $emailTemplate->format,
            );
        } catch (OrderEmailException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'order_id' => $order->getKey(),
            'template_id' => $emailTemplate->getKey(),
            'email_account_id' => $emailTemplate->email_account_id,
            'subject' => $rendered->subject,
            'format' => $rendered->format->value,
            'body' => $rendered->format === EmailTemplateFormat::Html
                ? $rendered->bodyHtml
                : $rendered->bodyText,
            'content_rendered' => true,
            'attachments' => $emailTemplate->attachments
                ->filter(fn ($attachment): bool => $attachment->type !== EmailTemplateAttachmentType::None)
                ->map(fn ($attachment): array => [
                    'slot' => $attachment->slot,
                    'name' => $attachment->type === EmailTemplateAttachmentType::Upload
                        ? (string) $attachment->original_name
                        : (string) $attachment->document_type?->label(),
                ])
                ->values(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function store(
        StoreOrderEmailMessageRequest $request,
        Order $order,
        OrderEmailPreparationService $preparation,
    ): JsonResponse {
        $validated = $request->validated();
        $template = isset($validated['email_template_id'])
            ? EmailTemplate::query()->where('is_hidden', false)->find($validated['email_template_id'])
            : null;
        $account = EmailAccount::query()->find($validated['email_account_id']);

        if (isset($validated['email_template_id']) && ! $template instanceof EmailTemplate) {
            return $this->domainError(new OrderEmailException(
                'template_missing',
                'Wybrany szablon e-mail nie istnieje.',
            ));
        }

        $user = $request->user();

        try {
            $message = $preparation->prepareAndQueue(
                $order,
                $template,
                new OrderEmailPreparationData(
                    toEmail: (string) $order->customer_email,
                    toName: $order->billing_name ?: ($order->shipping_name ?: $order->customer_login),
                    source: OrderEmailMessageSource::Manual,
                    idempotencyKey: $validated['idempotency_key'],
                    user: $user,
                    subjectTemplate: $validated['subject'],
                    bodyTemplate: $validated['body'],
                    format: EmailTemplateFormat::from($validated['format']),
                    emailAccount: $account,
                    actorName: $user?->name ?? 'Operator NEX-OMS',
                    contentIsRendered: $request->boolean('content_rendered'),
                ),
            );
        } catch (OrderEmailException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'message' => 'Wiadomość została dodana do kolejki wysyłkowej.',
            ...$this->messagePayload($order, $message),
        ], 201);
    }

    public function status(Order $order, OrderEmailMessage $orderEmailMessage): JsonResponse
    {
        $this->ensureMessageBelongsToOrder($order, $orderEmailMessage);

        return response()->json(
            $this->messagePayload($order, $orderEmailMessage),
        )->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function attachment(
        Order $order,
        OrderEmailMessage $orderEmailMessage,
        OrderEmailMessageAttachment $orderEmailMessageAttachment,
    ): StreamedResponse {
        $this->ensureMessageBelongsToOrder($order, $orderEmailMessage);
        abort_unless(
            $orderEmailMessageAttachment->order_email_message_id === $orderEmailMessage->getKey(),
            404,
        );

        $expectedPrefix = "order-email-messages/{$orderEmailMessage->getKey()}/attachments/";
        abort_unless(str_starts_with($orderEmailMessageAttachment->file_path, $expectedPrefix), 404);

        $disk = Storage::disk($orderEmailMessageAttachment->disk);
        abort_unless($disk->exists($orderEmailMessageAttachment->file_path), 404);

        return $disk->download(
            $orderEmailMessageAttachment->file_path,
            $orderEmailMessageAttachment->file_name,
            ['Content-Type' => $orderEmailMessageAttachment->mime_type],
        );
    }

    /** @return array<string, mixed> */
    private function messagePayload(Order $order, OrderEmailMessage $message): array
    {
        $message->loadMissing('attachments');

        return [
            'id' => $message->getKey(),
            'status' => $message->status->value,
            'polling_finished' => $message->status->isTerminal(),
            'status_url' => route('orders.email-messages.status', [$order, $message]),
            'html' => view('components.order-email-message-item', compact('order', 'message'))->render(),
        ];
    }

    private function ensureMessageBelongsToOrder(Order $order, OrderEmailMessage $message): void
    {
        abort_unless($message->order_id === $order->getKey(), 404);
    }

    private function domainError(OrderEmailException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'safe_code' => $exception->safeCode(),
        ], 422);
    }
}
