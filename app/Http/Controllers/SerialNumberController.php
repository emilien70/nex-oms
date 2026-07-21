<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\SerialNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SerialNumberController extends Controller
{
    public function store(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'serial_number' => ['nullable', 'string', 'max:255'],
            'serial_numbers_bulk' => ['nullable', 'string'],
        ]);

        $serialNumbers = $this->serialNumbersFromRequest($validated);

        if ($serialNumbers === []) {
            return back()
                ->withErrors(['serial_number' => 'Podaj numer seryjny albo liste numerow seryjnych.'])
                ->withInput();
        }

        $tooLong = collect($serialNumbers)->first(fn (string $serialNumber) => strlen($serialNumber) > 255);

        if ($tooLong) {
            return back()
                ->withErrors(['serial_numbers_bulk' => 'Numer seryjny jest za dlugi: ' . $tooLong])
                ->withInput();
        }

        $existingSerialNumbers = SerialNumber::query()
            ->whereIn('serial_number', $serialNumbers)
            ->pluck('serial_number')
            ->all();

        if ($existingSerialNumbers !== []) {
            return back()
                ->withErrors([
                    'serial_numbers_bulk' => 'Te numery seryjne juz istnieja: ' . implode(', ', $existingSerialNumbers),
                ])
                ->withInput();
        }

        DB::transaction(function () use ($order, $serialNumbers): void {
            foreach ($serialNumbers as $serialNumber) {
                $order->serialNumbers()->create([
                    'serial_number' => $serialNumber,
                    'source' => 'manual',
                    'scanned_at' => now(),
                ]);

                $order->events()->create([
                    'event_type' => 'serial_number_added',
                    'title' => 'Numer seryjny dodany',
                    'description' => 'Dodano numer seryjny: ' . $serialNumber,
                    'payload' => [
                        'serial_number' => $serialNumber,
                    ],
                ]);
            }
        });

        return back()->with('success', 'Dodano numery seryjne: ' . count($serialNumbers) . '.');
    }

    public function destroy(SerialNumber $serialNumber): RedirectResponse
    {
        $orderId = $serialNumber->order_id;
        $order = $serialNumber->order ?: Order::find($orderId);
        $deletedSerialNumber = $serialNumber->serial_number;

        DB::transaction(function () use ($serialNumber, $order, $deletedSerialNumber): void {
            $serialNumber->delete();

            $order?->events()->create([
                'event_type' => 'serial_number_deleted',
                'title' => html_entity_decode('Numer seryjny usuni&#281;ty', ENT_QUOTES, 'UTF-8'),
                'description' => html_entity_decode('Usuni&#281;to numer seryjny: ', ENT_QUOTES, 'UTF-8') . $deletedSerialNumber,
                'payload' => [
                    'serial_number' => $deletedSerialNumber,
                ],
            ]);
        });

        return back()->with('success', 'Usunieto numer seryjny: ' . $deletedSerialNumber . '.');
    }

    /**
     * @param  array{serial_number?: string|null, serial_numbers_bulk?: string|null}  $validated
     * @return array<int, string>
     */
    private function serialNumbersFromRequest(array $validated): array
    {
        $serialNumbers = [];

        if (! empty($validated['serial_number'])) {
            $serialNumbers[] = trim($validated['serial_number']);
        }

        if (! empty($validated['serial_numbers_bulk'])) {
            $bulkSerialNumbers = preg_split('/\r\n|\r|\n/', $validated['serial_numbers_bulk']);

            foreach ($bulkSerialNumbers ?: [] as $serialNumber) {
                $serialNumbers[] = trim($serialNumber);
            }
        }

        $serialNumbers = array_filter($serialNumbers, fn (string $serialNumber) => $serialNumber !== '');

        return array_values(array_unique($serialNumbers));
    }
}
