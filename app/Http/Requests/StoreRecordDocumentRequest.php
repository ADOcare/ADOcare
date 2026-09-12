<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRecordDocumentRequest extends FormRequest
{
    public function authorize()
    {
        // authorize in controller/policy where necessary; allow here for authenticated users
        return $this->user() != null;
    }

    public function rules()
    {
        return [
            'patient_id' => 'required|exists:patients,id',
            'branch_id' => 'nullable|exists:branches,id',
            'date' => 'nullable|date_format:Y-m-d',
            'record_data' => 'required|array',
            'form_spec' => 'nullable|array',
        ];
    }
}
