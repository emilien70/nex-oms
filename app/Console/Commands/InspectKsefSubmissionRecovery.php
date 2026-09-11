<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Services\KsefSubmissionRecoveryService;
use Throwable;

class InspectKsefSubmissionRecovery extends Command
{
    protected $signature = 'ksef:recover-submission {--submission= : ID jednej próby} {--apply : Zastosuj wyłącznie dozwolone lokalne recovery}';

    protected $description = 'Oceń przerwaną próbę KSeF; domyślnie bez zapisów. Nigdy nie wykonuje HTTP ani wysyłki.';

    public function handle(KsefSubmissionRecoveryService $recovery): int
    {
        $id = $this->option('submission');
        if (! is_string($id) || preg_match('/^[1-9]\d*$/D', $id) !== 1 || (string) (int) $id !== $id) {
            $this->error('Wymagane jest poprawne --submission=<id>.');

            return self::INVALID;
        }
        try {
            $result = $this->option('apply') ? $recovery->apply((int) $id) : $recovery->inspect((int) $id);
            $this->line(json_encode(['submission_id' => (int) $id] + $result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (ModelNotFoundException) {
            $this->error('Nie znaleziono wskazanej próby.');
        } catch (KsefApiException $exception) {
            $this->error($exception->safeCode);
        } catch (Throwable) {
            $this->error('ksef_submission_recovery_failed');
        }

        return self::FAILURE;
    }
}
