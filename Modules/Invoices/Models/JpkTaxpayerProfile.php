<?php

namespace Modules\Invoices\Models;

use Illuminate\Database\Eloquent\Model;

class JpkTaxpayerProfile extends Model
{
    protected $primaryKey = 'singleton_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['singleton_key'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer'];
    }

    public function formValues(): array
    {
        $values = [];
        foreach (['type', 'nip', 'name', 'first_name', 'last_name', 'birth_date', 'email', 'phone', 'office'] as $field) {
            $values['jpk_'.$field] = $this->getAttribute($field) ?? '';
        }

        return $values;
    }
}
