<?php

namespace App\Http\Requests;

use App\Enums\InvoiceBatchMode;
use App\Enums\InvoicePatientCategory;
use App\Http\Requests\Concerns\ValidatesInvoiceDates;
use App\Support\InvoiceDates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    use ValidatesInvoiceDates;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('patient_category')) {
            $this->merge([
                'patient_category' => InvoicePatientCategory::DOMESTIC->value,
            ]);
        }

        if (! $this->has('batch_mode')) {
            $this->merge([
                'batch_mode' => InvoiceBatchMode::COMBINED->value,
            ]);
        }

        $period = (string) $this->input('period', '');

        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            return;
        }

        try {
            $automaticDates = InvoiceDates::automaticDates($period);
        } catch (\Throwable) {
            return;
        }

        $this->merge([
            'issued_at' => $automaticDates['issued_at'],
            'due_date' => $automaticDates['due_date'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'insurance_company_id' => ['required_if:type,procedures,transport', 'nullable', 'integer', 'exists:insurance_companies,id'],
            'period' => ['required', 'date_format:Y-m'],
            'type' => ['required', 'string', 'in:procedures,transport,credit_note,debit_note'],
            'patient_category' => ['required', Rule::enum(InvoicePatientCategory::class)],
            'batch_mode' => ['required', Rule::enum(InvoiceBatchMode::class)],
            'amount' => ['required_if:type,credit_note,debit_note', 'numeric'],
            'related_invoice_id' => ['required_if:type,credit_note,debit_note', 'integer', 'exists:invoices,id'],
        ], $this->invoiceDateRules(true));
    }
}
