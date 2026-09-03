<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefOfflineBuyerClassification;
use Modules\Ksef\Enums\KsefOfflineDeliveryDocumentType;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\ValueObjects\KsefOfflinePresentationData;

final class KsefOfflineDeliveryPolicy
{
    public function __construct(
        private readonly KsefOfflinePresentationDataExtractor $presentations,
    ) {}

    public function primaryDocument(KsefOfflineIssuance $issuance): KsefOfflineDeliveryDocumentType
    {
        return $this->primaryFor($this->presentations->extract($issuance));
    }

    public function presentationFor(
        KsefOfflineIssuance $issuance,
        KsefOfflineDeliveryDocumentType $documentType,
    ): KsefOfflinePresentationData {
        $presentation = $this->presentations->extract($issuance);

        if ($this->primaryFor($presentation) !== $documentType) {
            throw match ($documentType) {
                KsefOfflineDeliveryDocumentType::OfflineInvoice => new KsefApiException(
                    'Faktura Offline nie może zostać wydana krajowemu nabywcy posiadającemu NIP przed przekazaniem jej do KSeF.',
                    'ksef_offline_invoice_delivery_not_allowed',
                ),
                KsefOfflineDeliveryDocumentType::TransactionConfirmation => new KsefApiException(
                    'Dla tego nabywcy podstawowym dokumentem wydawanym poza KSeF jest Faktura Offline.',
                    'ksef_transaction_confirmation_not_allowed',
                ),
            };
        }

        return $presentation;
    }

    private function primaryFor(KsefOfflinePresentationData $presentation): KsefOfflineDeliveryDocumentType
    {
        return match ($presentation->buyerClassification) {
            KsefOfflineBuyerClassification::DomesticPlNip => KsefOfflineDeliveryDocumentType::TransactionConfirmation,
            KsefOfflineBuyerClassification::NoPlNip => KsefOfflineDeliveryDocumentType::OfflineInvoice,
        };
    }
}
