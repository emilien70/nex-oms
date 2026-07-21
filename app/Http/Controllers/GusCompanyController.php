<?php

namespace App\Http\Controllers;

use App\Services\GusRegonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GusCompanyController extends Controller
{
    public function show(Request $request, GusRegonService $gus): JsonResponse
    {
        $nip = preg_replace('/\D+/', '', (string) $request->query('nip'));

        if (strlen($nip) !== 10) {
            return response()->json([
                'message' => 'NIP musi miec 10 cyfr.',
                'errors' => [
                    'nip' => ['NIP musi miec 10 cyfr.'],
                ],
            ], 422);
        }

        try {
            $company = $gus->findCompanyByNip($nip);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], str_contains($exception->getMessage(), 'GUS_API_KEY') ? 500 : 502);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Nie udalo sie pobrac danych z GUS.',
            ], 502);
        }

        if (! $company) {
            return response()->json([
                'message' => 'Nie znaleziono firmy dla podanego NIP.',
            ], 404);
        }

        return response()->json($company);
    }
}
