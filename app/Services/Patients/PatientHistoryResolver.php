<?php

namespace App\Services\Patients;

use App\Models\Patient;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PatientHistoryResolver
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

    public function insuranceOn(Patient $patient, CarbonInterface|string $date): array
    {
        return $this->resolveInsurance($patient->id, $date);
    }

    public function doctorOn(Patient $patient, CarbonInterface|string $date): array
    {
        return $this->resolveCategory('doctor', $patient->id, $date);
    }

    public function nurseOn(Patient $patient, CarbonInterface|string $date): array
    {
        return $this->resolveCategory('nurse', $patient->id, $date);
    }

    public function branchOn(Patient $patient, CarbonInterface|string $date): array
    {
        return $this->resolveCategory('branch', $patient->id, $date);
    }

    public function nameOn(Patient $patient, CarbonInterface|string $date): array
    {
        return $this->resolveCategory('name', $patient->id, $date);
    }

    public function addressOn(Patient $patient, CarbonInterface|string $date): array
    {
        return $this->resolveCategory('address', $patient->id, $date);
    }

    public function identityOn(Patient $patient, CarbonInterface|string $date): array
    {
        return $this->resolveCategory('identity', $patient->id, $date);
    }

    public function contactOn(Patient $patient, CarbonInterface|string $date): array
    {
        return $this->resolveCategory('contact', $patient->id, $date);
    }

    public function patientOn(Patient $patient, CarbonInterface|string $date): array
    {
        $date = Carbon::parse($date)->toDateString();

        return [
            'patient_id' => $patient->id,
            'date' => $date,
            'name' => $this->nameOn($patient, $date),
            'identity' => $this->identityOn($patient, $date),
            'contact' => $this->contactOn($patient, $date),
            'address' => $this->addressOn($patient, $date),
            'doctor' => $this->doctorOn($patient, $date),
            'nurse' => $this->nurseOn($patient, $date),
            'branch' => $this->branchOn($patient, $date),
            'insurance' => $this->insuranceOn($patient, $date),
        ];
    }

    /**
     * Resolve a set of patients using one query per history category.
     *
     * @param  EloquentCollection<int, Patient>|iterable<Patient>  $patients
     * @return array<int, array<string, mixed>>
     */
    public function patientsOn(iterable $patients, CarbonInterface|string $date): array
    {
        $patients = collect($patients);
        $date = Carbon::parse($date)->toDateString();
        $patientIds = $patients->pluck('id')->all();
        $records = [];

        foreach (self::HISTORY as $category => [$table]) {
            $records[$category] = DB::table($table)
                ->whereIn('patient_id', $patientIds)
                ->where(fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
                ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
                ->get()
                ->groupBy('patient_id');
        }

        $records['insurance'] = DB::table('patient_coverages')
            ->whereIn('patient_id', $patientIds)
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->get()
            ->groupBy('patient_id');

        $resolved = [];
        foreach ($patients as $patient) {
            $resolved[$patient->id] = [
                'patient_id' => $patient->id,
                'date' => $date,
            ];

            foreach (array_keys(self::HISTORY) as $category) {
                $resolved[$patient->id][$category] = $this->formatRecords(
                    $records[$category]->get($patient->id, collect()),
                    self::HISTORY[$category][1],
                );
            }

            $resolved[$patient->id]['insurance'] = $this->formatRecords(
                $records['insurance']->get($patient->id, collect()),
                [
                    'insurance_company_id',
                    'category',
                    'identification_method',
                    'member_state_code',
                    'foreign_insured_id',
                    'special_category',
                    'is_verified',
                    'verified_at',
                ],
            );
        }

        return $resolved;
    }

    public function historyFor(Patient $patient): array
    {
        $history = [];

        foreach (self::HISTORY as $category => [$table, $fields]) {
            $records = DB::table($table)
                ->where('patient_id', $patient->id)
                ->orderByRaw('valid_from IS NOT NULL')
                ->orderBy('valid_from')
                ->orderBy('id')
                ->get();

            $history = [...$history, ...$this->formatHistoryRows($category, $records, $fields)];
        }

        $coverageRecords = DB::table('patient_coverages')
            ->where('patient_id', $patient->id)
            ->orderByRaw('valid_from IS NOT NULL')
            ->orderBy('valid_from')
            ->orderBy('id')
            ->get();
        $history = [...$history, ...$this->formatHistoryRows('insurance', $coverageRecords, [
            'insurance_company_id',
            'category',
            'identification_method',
            'member_state_code',
            'foreign_insured_id',
            'special_category',
            'is_verified',
            'verified_at',
        ])];

        $corrections = DB::table('patient_history_corrections')
            ->where('patient_id', $patient->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($corrections as $correction) {
            $history[] = [
                'id' => 'correction-'.$correction->id,
                'category' => $correction->category,
                'previous_values' => json_decode($correction->before_values, true),
                'new_values' => json_decode($correction->after_values, true),
                'valid_from' => $correction->valid_from,
                'valid_to' => $correction->valid_to,
                'recorded_at' => $correction->created_at,
                'changed_by' => $correction->changed_by,
                'reason' => $correction->reason,
                'certainty' => $correction->valid_from === null ? 'unknown' : 'known',
                'is_correction' => true,
            ];
        }

        usort($history, static function (array $left, array $right): int {
            return [$left['valid_from'] ?? '', $left['category'], $left['id']]
                <=> [$right['valid_from'] ?? '', $right['category'], $right['id']];
        });

        $actorIds = collect($history)->pluck('changed_by')->filter()->unique()->values();
        $actors = $actorIds->isEmpty()
            ? collect()
            : DB::table('users')
                ->whereIn('id', $actorIds)
                ->get(['id', 'title', 'first_name', 'last_name'])
                ->keyBy('id');

        foreach ($history as &$row) {
            $actor = $actors->get($row['changed_by']);
            $row['changed_by_name'] = $actor
                ? trim(implode(' ', array_filter([$actor->title, $actor->first_name, $actor->last_name])))
                : null;
        }
        unset($row);

        return $history;
    }

    private function resolveCategory(string $category, int $patientId, CarbonInterface|string $date): array
    {
        [$table, $fields] = self::HISTORY[$category];
        $date = Carbon::parse($date)->toDateString();
        $records = DB::table($table)
            ->where('patient_id', $patientId)
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get();

        return $this->formatRecords($records, $fields);
    }

    private function resolveInsurance(int $patientId, CarbonInterface|string $date): array
    {
        $date = Carbon::parse($date)->toDateString();
        $records = DB::table('patient_coverages')
            ->where('patient_id', $patientId)
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get();

        return $this->formatRecords($records, [
            'insurance_company_id',
            'category',
            'identification_method',
            'member_state_code',
            'foreign_insured_id',
            'special_category',
            'is_verified',
            'verified_at',
        ]);
    }

    private function formatRecords(iterable $records, array $fields): array
    {
        $records = collect($records);

        if ($records->isEmpty()) {
            return ['status' => 'missing', 'values' => null, 'valid_from' => null, 'valid_to' => null];
        }

        if ($records->count() > 1) {
            return ['status' => 'conflict', 'values' => null, 'valid_from' => null, 'valid_to' => null];
        }

        $record = $records->first();

        return [
            'status' => $record->valid_from === null ? 'unknown' : 'known',
            'values' => collect($fields)->mapWithKeys(fn (string $field) => [$field => $record->{$field}])->all(),
            'valid_from' => $record->valid_from,
            'valid_to' => $record->valid_to,
            'recorded_at' => $record->created_at,
            'changed_by' => $record->changed_by ?? null,
            'reason' => $record->change_reason ?? null,
        ];
    }

    private function formatHistoryRows(string $category, iterable $records, array $fields): array
    {
        $records = collect($records);
        $rows = [];
        $previousValues = null;

        foreach ($records as $record) {
            $values = collect($fields)->mapWithKeys(fn (string $field) => [$field => $record->{$field}])->all();
            $rows[] = [
                'id' => $record->id,
                'category' => $category,
                'previous_values' => $previousValues,
                'new_values' => $values,
                'valid_from' => $record->valid_from,
                'valid_to' => $record->valid_to,
                'recorded_at' => $record->created_at,
                'changed_by' => $record->changed_by ?? null,
                'reason' => $record->change_reason ?? null,
                'certainty' => $record->valid_from === null ? 'unknown' : 'known',
            ];
            $previousValues = $values;
        }

        return $rows;
    }
}
