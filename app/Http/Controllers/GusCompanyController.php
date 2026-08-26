<?php

namespace App\Http\Controllers;

use App\Exceptions\GusRegonException;
use App\Services\GusRegonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GusCompanyController extends Controller
{
    public function show(Request $request, GusRegonService $gus): JsonResponse
    {
        $nip = preg_replace('/[\s-]+/u', '', trim((string) $request->input('nip')));

        if (! $this->isValidNip($nip)) {
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

    private function isValidNip(string $nip): bool
    {
        if (preg_match('/^\d{10}$/', $nip) !== 1 || $nip === '0000000000') {
            return false;
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += ((int) $nip[$index]) * $weight;
        }

        $checksum = $sum % 11;

        return $checksum !== 10 && $checksum === (int) $nip[9];
    }
}
