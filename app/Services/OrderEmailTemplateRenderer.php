<?php

namespace App\Services;

use App\Enums\EmailTemplateFormat;
use App\Exceptions\OrderEmailException;
use App\Models\Order;
use App\ValueObjects\RenderedOrderEmail;

class OrderEmailTemplateRenderer
{
    private const MAX_RENDERED_BODY_BYTES = 100_000;

    public function __construct(
        private readonly OrderVariableService $variables,
        private readonly OrderEmailHtmlSanitizer $htmlSanitizer,
    ) {}

    public function render(
        Order $order,
        string $subjectTemplate,
        string $bodyTemplate,
        EmailTemplateFormat $format,
    ): RenderedOrderEmail {
        $unknown = array_values(array_unique([
            ...$this->variables->unknownVariables($subjectTemplate),
            ...$this->variables->unknownVariables($bodyTemplate),
        ]));

        if ($unknown !== []) {
            throw new OrderEmailException(
                'unknown_variables',
                'Wiadomość zawiera nieznane zmienne: '.implode(', ', $unknown).'.',
            );
        }

        $subject = trim(preg_replace('/[\r\n]+/u', ' ', $this->variables->render($subjectTemplate, $order)) ?? '');

        if ($subject === '' || mb_strlen($subject) > 255) {
            throw new OrderEmailException(
                'subject_invalid',
                'Temat wiadomości jest pusty albo przekracza 255 znaków po podstawieniu zmiennych.',
            );
        }

        if ($format === EmailTemplateFormat::PlainText) {
            $bodyText = trim($this->variables->render($bodyTemplate, $order));

            if ($bodyText === '') {
                throw new OrderEmailException('body_empty', 'Treść wiadomości nie może być pusta.');
            }

            if (strlen($bodyText) > self::MAX_RENDERED_BODY_BYTES) {
                throw new OrderEmailException(
                    'body_too_long',
                    'Treść wiadomości jest zbyt długa po podstawieniu zmiennych.',
                );
            }

            return new RenderedOrderEmail($subject, $format, $bodyText, null);
        }

        $renderedHtml = $this->variables->renderForHtml($bodyTemplate, $order);

        if (strlen($renderedHtml) > self::MAX_RENDERED_BODY_BYTES) {
            throw new OrderEmailException(
                'body_too_long',
                'Treść wiadomości jest zbyt długa po podstawieniu zmiennych.',
            );
        }

        $bodyHtml = trim($this->htmlSanitizer->sanitize($renderedHtml));
        $bodyText = $this->plainText($bodyHtml);

        if ($bodyHtml === '' || $bodyText === '') {
            throw new OrderEmailException(
                'body_empty_after_sanitization',
                'Treść wiadomości jest pusta po bezpiecznym przetworzeniu HTML.',
            );
        }

        return new RenderedOrderEmail($subject, $format, $bodyText, $bodyHtml);
    }

    private function plainText(string $html): string
    {
        $withBreaks = preg_replace('/<\s*br\s*\/?>/iu', "\n", $html) ?? $html;
        $withBreaks = preg_replace('/<\/(p|div|li|h[1-6]|tr|blockquote)>/iu', "\n", $withBreaks) ?? $withBreaks;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n?|\x{2028}|\x{2029}/u", "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
