<?php

namespace Modules\Invoices\Http\Requests;

class UpdateCorrectionRequest extends CorrectionDraftRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'expected_lock_version' => ['required', 'integer', 'min:1'],
        ]);
    }
}
