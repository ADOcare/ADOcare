<?php

namespace App\Http\Requests;

class PreviewRecordDocumentRequest extends StoreRecordDocumentRequest
{
    public function rules()
    {
        return array_replace(parent::rules(), [
            'date' => 'nullable|date_format:Y-m-d',
        ]);
    }
}
