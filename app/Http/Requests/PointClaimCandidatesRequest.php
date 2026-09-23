<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PointClaimCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'batch_type' => ['required', 'string', 'in:N,O,A,E,F,G,I,J,K'],
            'insurance_company_id' => ['required', 'integer', 'exists:insurance_companies,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'period' => ['required', 'array', 'size:2'],
            'period.0' => ['required', 'date'],
            'period.1' => ['required', 'date', 'after_or_equal:period.0'],
        ];
    }
}
