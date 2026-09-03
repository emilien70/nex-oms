<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefOfflineDeliveryDocumentType;
use Modules\Ksef\Models\KsefOfflineIssuance;

final class KsefTransactionConfirmationPdfService
{
    public function __construct(
        private readonly KsefOfflineDeliveryPolicy $delivery,
        private readonly KsefOfflinePresentationPdfRenderer $renderer,
        private readonly KsefOfflinePdfFilenameGenerator $filenames,
    ) {}

    /** @return array{contents: string, filename: string} */
    public function document(KsefOfflineIssuance $issuance): array
    {
        $presentation = $this->delivery->presentationFor(
            $issuance,
            KsefOfflineDeliveryDocumentType::TransactionConfirmation,
        );

        return [
            'contents' => $this->renderer->renderTransactionConfirmation($presentation),
            'filename' => $this->filenames->transactionConfirmation($presentation->invoiceNumber),
        ];
    }
}
