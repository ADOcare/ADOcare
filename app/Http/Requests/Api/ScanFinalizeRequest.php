<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ScanFinalizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public token-based endpoint; access is validated by session token in controller/service.
        return true;
    }

    public function rules(): array
    {
        return [
            'session_token' => ['required', 'string'],
            'reference_date' => ['required', 'date_format:Y-m-d'],
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['required', 'file', 'max:102400'],
        ];
    }
}
