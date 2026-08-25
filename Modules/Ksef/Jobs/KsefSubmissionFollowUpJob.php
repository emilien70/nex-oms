<?php

namespace Modules\Ksef\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Ksef\Services\KsefSubmissionFollowUpProcessor;

class KsefSubmissionFollowUpJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor;

    public function __construct(public int $submissionId)
    {
        $this->uniqueFor = max(60, (int) config('ksef.follow_up.unique_for_seconds', 300));
        $this->onConnection('database');
        $this->onQueue((string) config('ksef.follow_up.queue', 'ksef'));
    }

    public function uniqueId(): string
    {
        return 'ksef-submission-'.$this->submissionId;
    }

    public function handle(KsefSubmissionFollowUpProcessor $processor): void
    {
        $processor->handle($this->submissionId);
    }
}
