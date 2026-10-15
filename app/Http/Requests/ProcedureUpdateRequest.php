<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcedureUpdateRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user() !== null;
    }

    public function rules()
    {
        $procedureId = $this->route('procedure')?->id ?? null;

        return [
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('procedures', 'code')->ignore($procedureId)],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'default_dekurz_text' => ['nullable', 'string', 'max:20000'],

            'prices' => ['sometimes', 'array', 'min:1'],
            'prices.*.insurance_company_id' => ['required_with:prices', 'integer', 'exists:insurance_companies,id'],
            'prices.*.price' => ['required_with:prices', 'numeric', 'min:0'],
        ];
    }
}
