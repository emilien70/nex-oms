<?php

namespace Modules\Invoices\Services;

use Carbon\CarbonInterface;
use DomainException;

class InvoiceNumberFormatter
{
    public function format(string $numberFormat, int $sequenceNumber, CarbonInterface $numberingDate): string
    {
        if ($sequenceNumber < 1) {
            throw new DomainException('Numer kolejny dokumentu musi być większy lub równy 1.');
        }

        if (preg_match('/%N+/', $numberFormat) !== 1) {
            throw new DomainException('Format numeracji musi zawierać token %N.');
        }

        $formatted = preg_replace_callback(
            '/%N+/',
            static function (array $matches) use ($sequenceNumber): string {
                $width = strlen($matches[0]) - 1;

                return $width > 1
                    ? str_pad((string) $sequenceNumber, $width, '0', STR_PAD_LEFT)
                    : (string) $sequenceNumber;
            },
            $numberFormat,
        );

        if (! is_string($formatted)) {
            throw new DomainException('Nie udało się sformatować numeru dokumentu.');
        }

        $formatted = str_replace(
            ['%M', '%Y', '%y'],
            [$numberingDate->format('m'), $numberingDate->format('Y'), $numberingDate->format('y')],
            $formatted,
        );

        if (preg_match('/%N+|%M|%Y|%y/', $formatted) === 1) {
            throw new DomainException('Format numeracji zawiera nierozwiązany token numeracyjny.');
        }

        return $formatted;
    }
}
