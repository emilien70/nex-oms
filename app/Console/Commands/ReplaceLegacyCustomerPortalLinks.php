<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ReplaceLegacyCustomerPortalLinks extends Command
{
    protected $signature = 'customer-portal:replace-legacy-links {--dry-run}';

    protected $description = 'Disabled: customer portal URLs are permanent and cannot be replaced.';

    public function handle(): int
    {
        $this->error('Linki portalu sa stale. Wymiana kodow jest zablokowana; nie zmieniono zadnego adresu.');

        return self::FAILURE;
    }
}
