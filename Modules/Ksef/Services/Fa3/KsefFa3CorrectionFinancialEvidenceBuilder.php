<?php

namespace Modules\Ksef\Services\Fa3;

use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionDocumentData;

final class KsefFa3CorrectionFinancialEvidenceBuilder
{
    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly KsefFa3CorrectionFinancialEvidenceValidator $validator,
    ) {}

    public function build(KsefFa3CorrectionDocumentData $data): array
    {
        $totals = ['net' => '0.00', 'vat' => '0.00', 'gross' => $data->invoice['total_gross']];
        $lines = [];
        foreach ($data->lines as $line) {
            $frozen = ['position' => $line['position']];
            foreach (['before', 'after'] as $side) {
                $frozen[$side] = array_intersect_key($line[$side], array_flip([
                    'fa3_rate', 'total_net', 'total_vat', 'total_gross',
                ]));
            }
            foreach (['net', 'vat'] as $field) {
                $totals[$field] = $this->decimal->add($totals[$field], $this->decimal->subtract(
                    $line['after']['total_'.$field], $line['before']['total_'.$field],
                ));
            }
            $lines[] = $frozen;
        }
        $evidence = [
            'version' => 1,
            'profile' => 'correction_financial',
            'currency' => $data->invoice['currency'],
            'lines' => $lines,
            'tax_buckets' => $data->taxBuckets,
            'totals' => $totals,
        ];
        $this->validator->validate($evidence);

        return $evidence;
    }
}
