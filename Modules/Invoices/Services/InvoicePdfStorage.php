<?php

namespace Modules\Invoices\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Throwable;

class InvoicePdfStorage
{
    public function __construct(
        private readonly InvoicePdfFilenameGenerator $filenames,
    ) {}

    /** @param callable(): string $generator */
    public function getOrCreate(Invoice $invoice, callable $generator): string
    {
        $disk = Storage::disk('local');
        $path = $this->filenames->storagePath($invoice);

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $temporary = dirname($path).'/.'.basename($path).'.'.bin2hex(random_bytes(8)).'.tmp';

        try {
            $contents = $generator();
            if (! str_starts_with($contents, '%PDF-') || ! $disk->put($temporary, $contents)) {
                throw new InvoiceDomainException(
                    'invoice_pdf_generation_failed',
                    'Nie udało się wygenerować pliku PDF dokumentu.',
                );
            }

            if ($disk->exists($path)) {
                $disk->delete($temporary);

                return (string) $disk->get($path);
            }

            $disk->makeDirectory(dirname($path));
            if (! @rename($disk->path($temporary), $disk->path($path))) {
                if ($disk->exists($path)) {
                    $disk->delete($temporary);
                } else {
                    throw new InvoiceDomainException(
                        'invoice_pdf_generation_failed',
                        'Nie udało się bezpiecznie zapisać pliku PDF dokumentu.',
                    );
                }
            }

            return (string) $disk->get($path);
        } catch (InvoiceDomainException $exception) {
            $disk->delete($temporary);
            throw $exception;
        } catch (Throwable $exception) {
            $disk->delete($temporary);
            throw new InvoiceDomainException(
                'invoice_pdf_generation_failed',
                'Nie udało się wygenerować pliku PDF dokumentu.',
                [],
                $exception,
            );
        }
    }
}
