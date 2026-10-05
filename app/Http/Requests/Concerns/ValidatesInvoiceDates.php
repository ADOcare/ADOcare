<?php

namespace App\Http\Requests\Concerns;

use App\Models\Invoice;
use App\Support\InvoiceDates;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

trait ValidatesInvoiceDates
{
    protected function invoiceDateRules(bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';

        return [
            'issued_at' => [$presence, 'date_format:Y-m-d'],
            'sent_at' => [$presence, 'date_format:Y-m-d'],
            'due_date' => [$presence, 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $routeInvoice = $this->route('invoice');
            $invoice = $routeInvoice instanceof Invoice ? $routeInvoice : null;

            $period = (string) $this->input('period', $invoice?->period ?? '');
            $type = (string) $this->input('type', $invoice?->type ?? '');
            $issuedAt = $this->input('issued_at', $invoice?->issued_at?->toDateString());
            $sentAt = $this->input('sent_at', $invoice?->sent_at?->toDateString());
            $dueDate = $this->input('due_date', $invoice?->due_date?->toDateString());

            if (!$issuedAt) {
                $validator->errors()->add('issued_at', 'Dátum vystavenia je povinný.');
            }

            if (!$sentAt) {
                $validator->errors()->add('sent_at', 'Dátum odoslania je povinný.');
            }

            if (!$dueDate) {
                $validator->errors()->add('due_date', 'Dátum splatnosti je povinný.');
            }

            if ($validator->errors()->any()) {
                return;
            }

            try {
                $issued = CarbonImmutable::createFromFormat('!Y-m-d', (string) $issuedAt, 'Europe/Bratislava');
                $sent = CarbonImmutable::createFromFormat('!Y-m-d', (string) $sentAt, 'Europe/Bratislava');
                $due = CarbonImmutable::createFromFormat('!Y-m-d', (string) $dueDate, 'Europe/Bratislava');
            } catch (\Throwable) {
                return;
            }

            if ($sent->lt($issued)) {
                $validator->errors()->add('sent_at', 'Dátum odoslania nemôže byť skorší ako dátum vystavenia.');
            }

            if ($due->lt($issued)) {
                $validator->errors()->add('due_date', 'Dátum splatnosti nemôže byť skorší ako dátum vystavenia.');
            }

            if (!$period || !InvoiceDates::appliesToMonthlyService($type)) {
                return;
            }

            try {
                $deliveryDate = InvoiceDates::deliveryDate($period);
                $latestIssueDate = InvoiceDates::latestIssueDate($period);
            } catch (\Throwable) {
                return;
            }

            if ($issued->lt($deliveryDate)) {
                $validator->errors()->add(
                    'issued_at',
                    'Dátum vystavenia nemôže byť skorší ako dátum dodania služby ' . $deliveryDate->format('d.m.Y') . '.'
                );
            }

            if ($issued->gt($latestIssueDate)) {
                $validator->errors()->add(
                    'issued_at',
                    'Faktúra musí byť vystavená najneskôr ' . $latestIssueDate->format('d.m.Y') . '.'
                );
            }
        });
    }
}
