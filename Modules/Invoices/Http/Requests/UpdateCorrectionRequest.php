<?php

namespace Modules\Invoices\Http\Requests;

class UpdateCorrectionRequest extends CorrectionDraftRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['expected_source_document_id'], $rules['expected_source_lock_version']);

        return array_merge($rules, [
            'expected_lock_version' => ['required', 'integer', 'min:1'],
        ]);
    }
}
