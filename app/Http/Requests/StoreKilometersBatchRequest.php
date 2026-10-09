<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKilometersBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'batchNumber' => ['nullable', 'regex:/^\d{6}$/'],
            'car_id' => ['nullable', 'integer', 'min:1'],
            'journeyIds' => ['sometimes', 'array', 'min:1'],
            'journeyIds.*' => ['integer', 'min:1', 'distinct'],
            'previewToken' => ['nullable', 'string', 'size:64'],
            'correction' => ['sometimes', 'array'],
            'correction.confirmed' => ['sometimes', 'boolean'],
            'correction.reason' => ['nullable', 'string', 'max:2000'],
            'insurance.id' => ['required', 'integer', 'min:1'],
            'period' => ['required', 'array', 'size:2'],
            'period.0' => ['required', 'date'],
            'period.1' => ['required', 'date', 'after_or_equal:period.0'],
            'branch.id' => ['required', 'integer', 'min:1'],
            'company.id' => ['nullable', 'integer', 'min:1'],
            'user.id' => ['nullable', 'integer', 'min:1'],

            'patients' => ['nullable', 'array'],
            'patients.*.id' => ['required', 'integer', 'min:1'],
            'batchType.code' => ['required', 'string', 'in:N,O,A,E,F,G,I,J,K'],

            'meta' => ['nullable', 'array'],
            'meta.fileName' => ['nullable', 'string', 'max:255'],
            'meta.amount' => ['nullable', 'numeric'],
            'meta.totalKilometers' => ['nullable', 'numeric'],
            'meta.performedBy' => ['nullable', 'string', 'max:255'],
            'meta.performedDate' => ['nullable', 'date'],
            'meta.companyName' => ['nullable', 'string', 'max:255'],
            'meta.branchName' => ['nullable', 'string', 'max:255'],
            'meta.insuranceName' => ['nullable', 'string', 'max:255'],
        ];
    }
}
