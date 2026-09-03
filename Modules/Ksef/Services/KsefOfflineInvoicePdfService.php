<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefOfflineDeliveryDocumentType;
use Modules\Ksef\Models\KsefOfflineIssuance;

final class KsefOfflineInvoicePdfService
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
            KsefOfflineDeliveryDocumentType::OfflineInvoice,
        );

        return [
            'contents' => $this->renderer->renderOfflineInvoice($presentation),
            'filename' => $this->filenames->offlineInvoice($presentation->invoiceNumber),
        ];
    }
}
