<?php

namespace Modules\Ksef\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Services\KsefAutomaticInvoiceSubmissionProcessor;

class KsefAutomaticInvoiceSubmissionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor;

    public string $environment;

    public function __construct(public int $invoiceId, KsefEnvironment $environment)
    {
        $this->environment = $environment->value;
        $this->uniqueFor = max(
            60,
            (int) config('ksef.automatic_submission.unique_for_seconds', 21600),
        );
        $this->onConnection('database');
        $this->onQueue((string) config('ksef.automatic_submission.queue', 'ksef'));
    }

    public function uniqueId(): string
    {
        return 'ksef-automatic-submission-'.$this->invoiceId.'-'.$this->environment;
    }

    public function handle(KsefAutomaticInvoiceSubmissionProcessor $processor): void
    {
        $environment = KsefEnvironment::tryFrom($this->environment);

        if ($environment === null) {
            return;
        }

        $processor->handle($this->invoiceId, $environment);
    }
}
