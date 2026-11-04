<?php

namespace Modules\Integrations\Allegro\Support;

use Illuminate\Http\Client\Response;

class AllegroApiDiagnostics
{
    public static function traceId(?Response $response): ?string
    {
        $value = $response?->header('Trace-Id');

        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,254}\z/', $value) === 1
            ? $value : null;
    }
}
