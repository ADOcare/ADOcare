<?php

namespace App\Http\Requests;

class PreviewProposalRequest extends StoreProposalRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'epicrisis_description' => 'nullable|string',
            'care_plan' => 'nullable|string',
            'expected_duration' => 'nullable|string',
        ]);
    }
}
