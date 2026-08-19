<?php

namespace Modules\Ksef\Enums;

enum KsefInvoiceSubmissionStatus: string
{
    case Preparing = 'preparing';
    case SessionOpened = 'session_opened';
    case Submitted = 'submitted';
    case Processing = 'processing';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case TechnicalFailed = 'technical_failed';
    case Uncertain = 'uncertain';

    public function blocksNewAttempt(): bool
    {
        return in_array($this, [
            self::Preparing,
            self::SessionOpened,
            self::Submitted,
            self::Processing,
            self::Accepted,
            self::Uncertain,
        ], true);
    }
}
