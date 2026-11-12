<?php

namespace App\Services\Patients;

use App\Models\Patient;
use App\Models\PatientCoverage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PatientHistoryWriter
{
    private const HISTORY = [
        'name' => ['patient_name_history', ['first_name', 'last_name', 'title']],
        'address' => ['patient_address_history', ['address', 'city', 'zip', 'country_id', 'latitude', 'longitude']],
        'doctor' => ['patient_doctor_history', ['doctor_id']],
        'nurse' => ['patient_nurse_history', ['nurse_id']],
        'branch' => ['patient_branch_history', ['branch_id']],
        'identity' => ['patient_identity_history', ['personal_number', 'sex']],
        'contact' => ['patient_contact_history', ['contact', 'country_code_phone', 'phone']],
    ];

    public function initialize(Patient $patient, ?string $validFrom, ?int $actorId): void
    {
        $now = now();

        foreach (self::HISTORY as [$table, $fields]) {
            $values = [];
            foreach ($fields as $field) {
                $values[$field] = $patient->{$field};
            }

            DB::table($table)->insert([
                'patient_id' => $patient->id,
                ...$values,
                'valid_from' => $validFrom,
                'valid_to' => null,
                'changed_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $coverage = $patient->coverages()->first();
        if ($coverage) {
            $coverage->update([
                'valid_from' => $validFrom,
                'changed_by' => $actorId,
            ]);
        }
    }

    public function apply(
        Patient $patient,
        array $attributes,
        string $effectiveDate,
        ?int $actorId,
        ?string $reason,
        array $effectiveDates = [],
    ): array
    {
        $changes = [];

        foreach (self::HISTORY as $category => [$table, $fields]) {
            $incoming = array_intersect_key($attributes, array_flip($fields));

            if ($incoming === []) {
                continue;
            }

            $date = Carbon::parse($effectiveDates[$category] ?? $effectiveDate)->toDateString();
            $change = $this->applyPeriod($patient, $table, $fields, $incoming, $date, $actorId, $reason);
            if ($change) {
                $changes[] = ['category' => $category, ...$change];
            }
        }

        return $changes;
    }

    public function applyCoverage(
        Patient $patient,
        array $attributes,
        string $effectiveDate,
        ?int $actorId,
        ?string $reason,
    ): PatientCoverage {
        $this->applyCoverageWithChange($patient, $attributes, $effectiveDate, $actorId, $reason);

        return $patient->currentCoverage()->firstOrFail();
    }

    public function applyCoverageWithChange(
        Patient $patient,
        array $attributes,
        string $effectiveDate,
        ?int $actorId,
        ?string $reason,
    ): ?array {
        $date = Carbon::parse($effectiveDate)->toDateString();
        $fields = [
            'insurance_company_id',
            'category',
            'identification_method',
            'member_state_code',
            'foreign_insured_id',
            'special_category',
            'is_verified',
        ];
        $change = $this->applyPeriod(
            $patient,
            'patient_coverages',
            $fields,
            array_intersect_key($attributes, array_flip($fields)),
            $date,
            $actorId,
            $reason,
            ['verified_at' => ($attributes['is_verified'] ?? false) ? now() : null],
        );

        return $change ? ['category' => 'insurance', ...$change] : null;
    }

    public function correct(
        Patient $patient,
        string $category,
        int $recordId,
        array $changes,
        int $actorId,
        string $reason,
    ): ?array {
        $table = $category === 'insurance'
            ? 'patient_coverages'
            : (self::HISTORY[$category][0] ?? null);
        $fields = $category === 'insurance'
            ? [
                'insurance_company_id',
                'category',
                'identification_method',
                'member_state_code',
                'foreign_insured_id',
                'special_category',
                'is_verified',
                'verified_at',
            ]
            : (self::HISTORY[$category][1] ?? []);

        if (! $table || $changes === [] || array_diff(array_keys($changes), $fields) !== []) {
            throw ValidationException::withMessages([
                'values' => ['Oprava obsahuje neplatnú kategóriu alebo polia.'],
            ]);
        }

        return DB::transaction(function () use ($patient, $category, $recordId, $changes, $actorId, $reason, $table, $fields) {
            Patient::query()->lockForUpdate()->findOrFail($patient->id);
            $record = DB::table($table)
                ->where('patient_id', $patient->id)
                ->where('id', $recordId)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw ValidationException::withMessages([
                    'record_id' => ['Historický záznam sa nenašiel.'],
                ]);
            }

            $before = collect($fields)->mapWithKeys(fn (string $field) => [$field => $record->{$field}])->all();
            $after = array_replace($before, $changes);
            if ($after == $before) {
                return null;
            }
            if ($category === 'insurance') {
                $this->assertValidCoverage($after);
            }

            DB::table($table)->where('id', $recordId)->update([
                ...$changes,
                'updated_at' => now(),
            ]);

            $correctionId = DB::table('patient_history_corrections')->insertGetId([
                'patient_id' => $patient->id,
                'category' => $category,
                'record_id' => $recordId,
                'valid_from' => $record->valid_from,
                'valid_to' => $record->valid_to,
                'before_values' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
                'changed_by' => $actorId,
                'reason' => trim($reason),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'id' => $correctionId,
                'category' => $category,
                'record_id' => $recordId,
                'valid_from' => $record->valid_from,
                'valid_to' => $record->valid_to,
                'before_values' => $before,
                'after_values' => $after,
                'changed_by' => $actorId,
                'reason' => trim($reason),
                'journal_item' => [
                    'category' => $category,
                    'previous_values' => $before,
                    'new_values' => $after,
                    'effective_date' => $record->valid_from,
                    'metadata' => [
                        'correction_id' => $correctionId,
                        'record_id' => $recordId,
                        'valid_to' => $record->valid_to,
                    ],
                ],
            ];
        });
    }

    public function correctAtDates(
        Patient $patient,
        array $categoryChanges,
        int $actorId,
        string $reason,
    ): array {
        $results = [];

        foreach ($categoryChanges as $category => $change) {
            $table = $category === 'insurance'
                ? 'patient_coverages'
                : (self::HISTORY[$category][0] ?? null);
            if (! $table) {
                throw ValidationException::withMessages([
                    'effective_dates' => ['Oprava obsahuje neplatnú kategóriu.'],
                ]);
            }

            $date = Carbon::parse($change['effective_date'])->toDateString();
            $record = DB::table($table)
                ->where('patient_id', $patient->id)
                ->where(fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
                ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw ValidationException::withMessages([
                    "effective_dates.{$category}" => ['Pre zvolený dátum neexistuje historické obdobie na opravu.'],
                ]);
            }

            $result = $this->correct(
                $patient,
                $category,
                (int) $record->id,
                $change['values'],
                $actorId,
                $reason,
            );
            if ($result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    private function applyPeriod(
        Patient $patient,
        string $table,
        array $fields,
        array $incoming,
        string $date,
        ?int $actorId,
        ?string $reason,
        array $extraValues = [],
    ): ?array {
        $records = DB::table($table)
            ->where('patient_id', $patient->id)
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->lockForUpdate()
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get();

        if ($records->count() > 1) {
            throw ValidationException::withMessages([
                'effective_date' => ['História pacienta obsahuje pre zvolený dátum konfliktné obdobia.'],
            ]);
        }

        $previous = $records->first();
        $next = null;
        if (! $previous) {
            $next = DB::table($table)
                ->where('patient_id', $patient->id)
                ->whereDate('valid_from', '>', $date)
                ->orderBy('valid_from')
                ->lockForUpdate()
                ->first();
        }

        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $previous
                ? $previous->{$field}
                : ($next ? $next->{$field} : $patient->{$field});
        }

        $values = array_replace($values, $incoming);
        $values = array_replace($values, $extraValues);
        if ($previous) {
            $same = true;
            foreach ($fields as $field) {
                if (($values[$field] ?? null) != ($previous->{$field} ?? null)) {
                    $same = false;
                    break;
                }
            }

            if ($same) {
                return null;
            }

            if ($previous->valid_from === $date) {
                throw ValidationException::withMessages([
                    'effective_date' => [
                        'Záznam s rovnakým dátumom platnosti nemožno prepísať bežnou úpravou. Použite opravu histórie.',
                    ],
                ]);
            }

            DB::table($table)->where('id', $previous->id)->update([
                'valid_to' => Carbon::parse($date)->subDay()->toDateString(),
                'updated_at' => now(),
            ]);
            $validTo = $previous->valid_to;
        } else {
            $validTo = $next
                ? Carbon::parse($next->valid_from)->subDay()->toDateString()
                : null;
        }

        $recordId = DB::table($table)->insertGetId([
            'patient_id' => $patient->id,
            ...$values,
            'valid_from' => $date,
            'valid_to' => $validTo,
            'changed_by' => $actorId,
            'change_reason' => $reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'record_id' => $recordId,
            'previous_values' => $previous
                ? collect($fields)->mapWithKeys(fn (string $field) => [$field => $previous->{$field}])->all()
                : null,
            'new_values' => collect($fields)->mapWithKeys(fn (string $field) => [$field => $values[$field] ?? null])->all(),
            'effective_date' => $date,
            'metadata' => ['valid_to' => $validTo],
        ];
    }

    private function assertValidCoverage(array $coverage): void
    {
        $category = $coverage['category'] ?? null;
        $method = $coverage['identification_method'] ?? null;
        $specialCategory = $coverage['special_category'] ?? null;

        $valid = in_array($category, ['domestic', 'eu', 'special'], true)
            && in_array($method, ['slovak_identifier', 'foreign_triad'], true);

        if ($category === 'domestic') {
            $valid = $valid
                && $method === 'slovak_identifier'
                && $specialCategory === null
                && ($coverage['member_state_code'] ?? null) === null
                && ($coverage['foreign_insured_id'] ?? null) === null;
        } elseif ($category === 'eu') {
            $valid = $valid
                && $method === 'foreign_triad'
                && filled($coverage['member_state_code'] ?? null)
                && filled($coverage['foreign_insured_id'] ?? null)
                && $specialCategory === null;
        } elseif ($category === 'special') {
            $valid = $valid
                && in_array($specialCategory, ['homeless', 'non_eu_foreigner', 'statutory_entitlement_9_3'], true);
        }

        if (! $valid) {
            throw ValidationException::withMessages([
                'values' => ['Opravené údaje poistenia nespĺňajú pravidlá klasifikácie.'],
            ]);
        }
    }
}
