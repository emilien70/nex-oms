<?php

namespace Modules\Invoices\Services;

class InvoicePdfFontResolver
{
    public function heading(): string
    {
        return 'helvetica';
    }

    public function body(): string
    {
        return $this->registeredVerdanaIsAvailable() ? 'verdana' : 'dejavusans';
    }

    private function registeredVerdanaIsAvailable(): bool
    {
        if (! class_exists(\TCPDF_FONTS::class)) {
            return false;
        }

        $path = \TCPDF_FONTS::getFontFullPath('verdana');

        return is_string($path) && is_file($path);
    }
}
