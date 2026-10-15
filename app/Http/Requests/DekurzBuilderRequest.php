<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DekurzBuilderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
        ];
    }
}