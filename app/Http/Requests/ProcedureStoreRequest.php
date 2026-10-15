<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcedureStoreRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user() !== null;
    }

    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:50', 'unique:procedures,code'],
            'description' => ['required', 'string', 'max:255'],
            'default_dekurz_text' => ['nullable', 'string', 'max:20000'],

            'prices' => ['required', 'array', 'min:1'],
            'prices.*.insurance_company_id' => ['required', 'integer', 'exists:insurance_companies,id'],
            'prices.*.price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
