<?php

namespace Modules\Integrations\VonHalsky\Exceptions;

class VonHalskyCommandUncertainException extends VonHalskyApiException
{
    public function __construct(public readonly ?string $commandId = null)
    {
        parent::__construct('Wynik przekazania do Von Halsky jest niepewny. Nie ponawiaj operacji bez wyjaśnienia wyniku.');
    }
}
