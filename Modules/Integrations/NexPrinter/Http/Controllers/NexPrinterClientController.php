<?php

namespace Modules\Integrations\NexPrinter\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Integrations\NexPrinter\Enums\NexPrintJobStatus;
use Modules\Integrations\NexPrinter\Exceptions\NexPrinterException;
use Modules\Integrations\NexPrinter\Models\NexPrinterAgent;
use Modules\Integrations\NexPrinter\Models\NexPrintJob;
use Modules\Integrations\NexPrinter\Services\NexPrinterPairingService;
use Modules\Integrations\NexPrinter\Services\NexPrinterRegistry;
use Modules\Integrations\NexPrinter\Services\NexPrintJobService;

class NexPrinterClientController extends Controller
{
    public function pair(
        Request $request,
        NexPrinterPairingService $pairing,
    ): JsonResponse {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:40'],
            'client_version' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $result = $pairing->pair(
                $validated['code'],
                $validated['name'],
                $validated['platform'] ?? 'windows',
                $validated['client_version'] ?? null,
            );
        } catch (NexPrinterException $exception) {
            return $this->error($exception, 422);
        }

        return response()->json([
            'agent' => [
                'id' => $result['agent']->public_id,
                'name' => $result['agent']->name,
            ],
            'token' => $result['token'],
        ], 201);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_version' => ['nullable', 'string', 'max:40'],
        ]);
        $agent = $this->agent($request);
        $agent->forceFill([
            'client_version' => $validated['client_version'] ?? $agent->client_version,
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['status' => 'ok']);
    }

    public function synchronizePrinters(
        Request $request,
        NexPrinterRegistry $registry,
    ): JsonResponse {
        $validated = $request->validate([
            'printers' => ['required', 'array', 'max:100'],
            'printers.*.system_name' => ['required', 'string', 'max:255', 'distinct'],
            'printers.*.display_name' => ['required', 'string', 'max:255'],
            'printers.*.is_default' => ['sometimes', 'boolean'],
            'printers.*.capabilities' => ['nullable', 'array'],
        ]);

        $registry->synchronize($this->agent($request), $validated['printers']);

        return response()->json(['status' => 'ok']);
    }

    public function claim(
        Request $request,
        NexPrintJobService $jobs,
    ): JsonResponse|Response {
        $claimed = $jobs->claim($this->agent($request));

        if ($claimed === null) {
            return response()->noContent();
        }

        $job = $claimed['job'];

        return response()->json([
            'job' => [
                'id' => $job->public_id,
                'printer' => [
                    'system_name' => $job->printer?->system_name,
                    'display_name' => $job->printer_name_snapshot,
                ],
                'document_type' => $job->document_type,
                'document_number' => $job->document_number,
                'filename' => $job->payload_filename,
                'content_type' => $job->content_type,
                'size' => $job->payload_size,
                'sha256' => $job->payload_sha256,
                'copies' => $job->copies,
                'payload_url' => route('nex-printer.api.jobs.payload', $job->public_id),
            ],
            'lease_token' => $claimed['lease_token'],
        ]);
    }

    public function payload(
        Request $request,
        NexPrintJob $printJob,
        NexPrintJobService $jobs,
    ): Response {
        try {
            $contents = $jobs->payload(
                $printJob,
                $this->agent($request),
                (string) $request->header('X-NEX-Printer-Lease'),
            );
        } catch (NexPrinterException $exception) {
            return response($exception->getMessage(), 409, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$printJob->payload_filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function complete(
        Request $request,
        NexPrintJob $printJob,
        NexPrintJobService $jobs,
    ): JsonResponse {
        $validated = $request->validate([
            'status' => ['required', 'in:spooled,failed'],
            'error_code' => ['nullable', 'string', 'max:100'],
            'error_message' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $job = $jobs->complete(
                $printJob,
                $this->agent($request),
                (string) $request->header('X-NEX-Printer-Lease'),
                NexPrintJobStatus::from($validated['status']),
                $validated['error_code'] ?? null,
                $validated['error_message'] ?? null,
            );
        } catch (NexPrinterException $exception) {
            return $this->error($exception, 409);
        }

        return response()->json([
            'job' => [
                'id' => $job->public_id,
                'status' => $job->status->value,
            ],
        ]);
    }

    private function agent(Request $request): NexPrinterAgent
    {
        /** @var NexPrinterAgent $agent */
        $agent = $request->attributes->get('nex_printer_agent');

        return $agent;
    }

    private function error(NexPrinterException $exception, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ],
        ], $status);
    }
}
