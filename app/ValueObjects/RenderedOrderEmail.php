<?php

namespace App\ValueObjects;

use App\Enums\EmailTemplateFormat;

final readonly class RenderedOrderEmail
{
    public function __construct(
        public string $subject,
        public EmailTemplateFormat $format,
        public string $bodyText,
        public ?string $bodyHtml,
    ) {}
}
