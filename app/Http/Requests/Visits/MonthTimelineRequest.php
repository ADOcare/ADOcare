<?php
namespace App\Http\Requests\Visits;

use Illuminate\Foundation\Http\FormRequest;

class MonthTimelineRequest extends FormRequest
{
    public function authorize()
    {
        // additional authorization could be added later
        return true;
    }

    public function rules()
    {
        return [
            'month' => 'required|date',
            'branch_id' => 'required|integer|exists:branches,id',
            'user_id' => 'nullable|integer',
            'procedure_codes' => 'nullable|array',
            'procedure_codes.*' => 'string',
            'patients' => 'nullable|array',
            'patients.*' => 'integer',
            'persist' => 'nullable|boolean',
            'car_id' => 'nullable|integer|min:1',
        ];
    }
}
