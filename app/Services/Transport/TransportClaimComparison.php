<?php

namespace App\Services\Transport;

use App\Services\Claims\InsuredClaimResolver;
use Illuminate\Support\Str;

class TransportClaimComparison
{
    public function snapshot(array $row, string $car): array
    {
        $insured = (new InsuredClaimResolver())->resolve($row, 'N');

        return [
            'date' => substr((string) ($row['date'] ?? ''), 0, 10),
            'personal_number' => $this->text($insured->personalNumber),
            'patient_name' => $this->text(trim(($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? '')), 60),
            'diagnosis_code' => $this->text($row['diagnosis_code'] ?? ''),
            'branch_city' => $this->text($row['branch_city'] ?? '', 50),
            'branch_address' => $this->text($row['branch_address'] ?? '', 50),
            'patient_city' => $this->text($row['patient_city'] ?? '', 50),
            'patient_address' => $this->text($row['patient_address'] ?? '', 50),
            'reported_km' => (string) (int) ($row['reported_km'] ?? 0),
            'car' => strtoupper(trim($car)),
            'doctor_pzs' => strtoupper(trim((string) ($row['doctor_pzs'] ?? ''))),
            'doctor_zpr' => strtoupper(trim((string) ($row['doctor_zpr'] ?? ''))),
            'member_state_code' => strtoupper(trim((string) $insured->memberStateCode)),
            'foreign_insured_id' => $this->text($insured->foreignInsuredId),
            'sex' => strtoupper(trim((string) $insured->sex)),
        ];
    }

    public function changes(array $before, array $after): array
    {
        $labels = [
            'date' => 'Dátum', 'personal_number' => 'Rodné číslo / BIČ', 'patient_name' => 'Meno poistenca',
            'diagnosis_code' => 'Diagnóza', 'branch_city' => 'Východisková obec',
            'branch_address' => 'Východisková ulica', 'patient_city' => 'Cieľová obec',
            'patient_address' => 'Cieľová ulica', 'reported_km' => 'Vykázané kilometre',
            'car' => 'EČV', 'doctor_pzs' => 'Kód odosielateľa PZS', 'doctor_zpr' => 'Kód odosielateľa ZPR',
            'member_state_code' => 'Štát poistenia', 'foreign_insured_id' => 'Zahraničné číslo poistenca',
            'sex' => 'Pohlavie',
        ];
        $changes = [];
        foreach ($labels as $field => $label) {
            if ((string) ($before[$field] ?? '') !== (string) ($after[$field] ?? '')) {
                $changes[] = ['field' => $field, 'label' => $label,
                    'before' => (string) ($before[$field] ?? ''), 'after' => (string) ($after[$field] ?? '')];
            }
        }

        return $changes;
    }

    private function text(?string $value, ?int $limit = null): string
    {
        $value = Str::ascii((string) $value);
        $value = str_replace(["\r", "\n", '|'], ' ', $value);
        $value = trim(preg_replace('/[^\x20-\x7E]/', '', $value) ?? '');

        return $limit === null ? $value : rtrim(mb_substr($value, 0, $limit));
    }
}
