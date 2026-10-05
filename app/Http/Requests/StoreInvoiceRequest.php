<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesInvoiceDates;
use App\Support\InvoiceDates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    use ValidatesInvoiceDates;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $period = (string) $this->input('period', '');

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            return;
        }

        try {
            $automaticDates = InvoiceDates::automaticDates($period);
        } catch (\Throwable) {
            return;
        }

        $issuedAt = (string) $this->input('issued_at', '');
        $sentAt = (string) $this->input('sent_at', '');
        $dueDate = (string) $this->input('due_date', '');

        if (InvoiceDates::appliesToMonthlyService((string) $this->input('type', ''))) {
            $deliveryDate = InvoiceDates::deliveryDate($period)->toDateString();
            $latestIssueDate = InvoiceDates::latestIssueDate($period)->toDateString();

            if (!$this->isIsoDate($issuedAt) || $issuedAt < $deliveryDate || $issuedAt > $latestIssueDate) {
                $issuedAt = $automaticDates['issued_at'];
            }
        } elseif (!$this->isIsoDate($issuedAt)) {
            $issuedAt = $automaticDates['issued_at'];
        }

        if (!$this->isIsoDate($sentAt) || $sentAt < $issuedAt) {
            $sentAt = max($automaticDates['sent_at'], $issuedAt);
        }

        if (!$this->isIsoDate($dueDate) || $dueDate < $issuedAt) {
            $dueDate = CarbonImmutable::parse($sentAt, 'Europe/Bratislava')
                ->addDays(InvoiceDates::DEFAULT_DUE_DAYS)
                ->toDateString();
        }

        $this->merge([
            'issued_at' => $issuedAt,
            'sent_at' => $sentAt,
            'due_date' => $dueDate,
        ]);
    }

    private function isIsoDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Europe/Bratislava')
                ->toDateString() === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'insurance_company_id' => ['nullable', 'integer', 'exists:insurance_companies,id'],
            'period' => ['required', 'date_format:Y-m'],
            'type' => ['required', 'string', 'in:procedures,transport,credit_note,debit_note'],
            'amount' => ['required_if:type,credit_note,debit_note', 'numeric'],
            'related_invoice_id' => ['required_if:type,credit_note,debit_note', 'integer', 'exists:invoices,id'],
        ], $this->invoiceDateRules(true));
    }
}
