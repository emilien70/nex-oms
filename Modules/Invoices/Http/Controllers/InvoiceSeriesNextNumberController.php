<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Http\Requests\PreviewInvoiceSeriesNextNumberRequest;
use Modules\Invoices\Http\Requests\SetInvoiceSeriesNextNumberRequest;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoiceNumberingService;
use Modules\Invoices\ValueObjects\InvoiceNumberPreview;
use Throwable;

class InvoiceSeriesNextNumberController extends Controller
{
    public function __construct(
        private readonly InvoiceNumberingService $numbering,
    ) {}

    public function show(Request $request, InvoiceSeries $series): View
    {
        $numberingDate = $this->initialNumberingDate($request, $series);
        $candidate = $this->initialCandidate($request);

        try {
            $preview = $this->numbering->previewNextNumber($series, $numberingDate, $candidate);
        } catch (DomainException $exception) {
            abort(422, $exception->getMessage());
        }

        return view('invoices.series.next-number._form', $this->viewData(
            $series,
            $numberingDate,
            $preview,
        ));
    }

    public function preview(
        PreviewInvoiceSeriesNextNumberRequest $request,
        InvoiceSeries $series,
    ): JsonResponse {
        try {
            $numberingDate = $request->numberingDate();
            $preview = $this->numbering->previewNextNumber(
                $series,
                $numberingDate,
                $request->candidateNextSequenceNumber(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            ...$preview->toArray(),
            'period_description' => $this->periodDescription($series, $numberingDate),
        ]);
    }

    public function store(
        SetInvoiceSeriesNextNumberRequest $request,
        InvoiceSeries $series,
    ): RedirectResponse {
        try {
            $numberingDate = $request->numberingDate();
            $nextSequenceNumber = $request->nextSequenceNumber();
            $this->numbering->setNextNumber(
                $series,
                $numberingDate,
                $nextSequenceNumber,
                (string) $request->validated('reason'),
                $this->actorSnapshot($request),
            );
            $preview = $this->numbering->previewNextNumber($series, $numberingDate);
        } catch (DomainException $exception) {
            return redirect()
                ->route('invoices.series.index')
                ->withErrors(['next_sequence_number' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('invoices.series.index')
            ->with(
                'success',
                "Dla serii {$series->name} i okresu {$preview->numberingPeriodKey} ustawiono następny numer {$nextSequenceNumber}. "
                ."Przewidywany numer dokumentu: {$preview->formattedNumber}.",
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(
        InvoiceSeries $series,
        CarbonImmutable $numberingDate,
        InvoiceNumberPreview $preview,
    ): array {
        return [
            'series' => $series,
            'numberingDate' => $numberingDate,
            'preview' => $preview,
            'periodDescription' => $this->periodDescription($series, $numberingDate),
        ];
    }

    private function initialNumberingDate(Request $request, InvoiceSeries $series): CarbonImmutable
    {
        try {
            return match ($series->reset_period) {
                InvoiceSeriesResetPeriod::Monthly => CarbonImmutable::createFromFormat(
                    '!Y-m',
                    (string) $request->old('period_month', now()->format('Y-m')),
                ),
                InvoiceSeriesResetPeriod::Yearly => CarbonImmutable::create(
                    (int) $request->old('period_year', $this->currentFiscalPeriodYear($series)),
                    $series->fiscal_year_start_month,
                    1,
                )->startOfDay(),
                InvoiceSeriesResetPeriod::None => CarbonImmutable::now()->startOfDay(),
            };
        } catch (Throwable) {
            return CarbonImmutable::now()->startOfDay();
        }
    }

    private function initialCandidate(Request $request): ?int
    {
        $candidate = $request->old('next_sequence_number');

        return is_numeric($candidate) && (int) $candidate >= 1 ? (int) $candidate : null;
    }

    private function currentFiscalPeriodYear(InvoiceSeries $series): int
    {
        $now = CarbonImmutable::now();

        return $now->month >= $series->fiscal_year_start_month
            ? $now->year
            : $now->year - 1;
    }

    private function periodDescription(InvoiceSeries $series, CarbonImmutable $numberingDate): string
    {
        return match ($series->reset_period) {
            InvoiceSeriesResetPeriod::Monthly => 'Miesiąc '.$numberingDate->format('Y-m'),
            InvoiceSeriesResetPeriod::Yearly => $this->yearlyPeriodDescription($numberingDate),
            InvoiceSeriesResetPeriod::None => 'Numeracja bez resetowania',
        };
    }

    private function yearlyPeriodDescription(CarbonImmutable $periodStart): string
    {
        $periodEnd = $periodStart->addYear()->subDay();

        return "Okres numeracji {$periodStart->year} obejmuje {$periodStart->format('d.m.Y')}–{$periodEnd->format('d.m.Y')}.";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function actorSnapshot(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return [
            'type' => 'user',
            'id' => $user->getAuthIdentifier(),
            'name' => $user->name ?? null,
            'email' => $user->email ?? null,
        ];
    }
}
