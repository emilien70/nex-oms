<?php

namespace App\Http\Controllers;

use App\Exceptions\GusRegonException;
use App\Services\GusRegonService;
use App\Support\PolishNip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GusCompanyController extends Controller
{
    public function show(Request $request, GusRegonService $gus): JsonResponse
    {
        $nip = PolishNip::withoutSeparators(trim((string) $request->input('nip')));

        if (! PolishNip::isValid($nip)) {
            return response()->json([
                'message' => 'Wpisz prawidłowy polski NIP.',
                'errors' => [
                    'nip' => ['Wpisz prawidłowy polski NIP.'],
                ],
            ], 422);
        }

        try {
            $companies = $gus->findCompaniesByNip($nip);
        } catch (GusRegonException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->safeCode,
            ], $exception->httpStatus);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Nie udało się pobrać danych z GUS.',
                'code' => 'gus_unexpected_error',
            ], 502);
        }

        if ($companies === []) {
            return response()->json([
                'message' => 'Nie znaleziono firmy dla podanego NIP.',
            ], 404);
        }

        return response()->json(['companies' => $companies]);
    }
}
