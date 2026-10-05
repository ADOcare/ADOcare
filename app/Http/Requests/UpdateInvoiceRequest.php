<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesInvoiceDates;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    use ValidatesInvoiceDates;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'insurance_company_id' => ['nullable', 'integer', 'exists:insurance_companies,id'],
            'period' => ['sometimes', 'date_format:Y-m'],
            'type' => ['sometimes', 'string', 'in:procedures,transport,credit_note,debit_note'],
            'amount' => ['sometimes', 'numeric'],
            'related_invoice_id' => ['sometimes', 'nullable', 'integer', 'exists:invoices,id'],
            'invoice_number' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ], $this->invoiceDateRules(false));
    }
}
