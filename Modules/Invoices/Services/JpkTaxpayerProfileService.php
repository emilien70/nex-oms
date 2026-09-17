<?php

namespace Modules\Invoices\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\JpkTaxpayerProfile;

final class JpkTaxpayerProfileService
{
    public function current(): ?JpkTaxpayerProfile
    {
        return JpkTaxpayerProfile::query()->find('default');
    }

    public function save(array $input, int $expectedVersion): void
    {
        $values = JpkV7m3Context::taxpayerData($input);
        $attributes = [];
        foreach ($values as $key => $value) {
            $attributes[substr($key, 4)] = $value === '' ? null : $value;
        }
        try {
            DB::transaction(function () use ($attributes, $expectedVersion): void {
                if ($expectedVersion === 0) {
                    JpkTaxpayerProfile::query()->insert($attributes + ['singleton_key' => 'default', 'lock_version' => 1,
                        'created_at' => now(), 'updated_at' => now()]);

                    return;
                }
                $updated = JpkTaxpayerProfile::query()->whereKey('default')->where('lock_version', $expectedVersion)
                    ->update($attributes + ['lock_version' => $expectedVersion + 1, 'updated_at' => now()]);
                if ($updated !== 1) {
                    throw $this->conflict();
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->conflict();
        }
    }

    private function conflict(): InvoiceDomainException
    {
        return new InvoiceDomainException('jpk_profile_conflict', 'Profil został zmieniony w innej karcie. Otwórz go ponownie i sprawdź dane przed zapisem.');
    }
}
