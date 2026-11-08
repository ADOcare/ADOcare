<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Patient;
use App\Models\PatientCoverage;
use App\Models\User;
use App\Support\AccentInsensitiveSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PatientService
{
    public function __construct(private EoverenieInsuranceService $insuranceVerification) {}

    public function queryForUserBranch(User $nurse, Branch $branch): Builder
    {
        return Patient::query()
            ->withCount('openPatientAuditIssues')
            ->where('nurse_id', $nurse->id)
            ->where('branch_id', $branch->id);
    }

    public function queryForPatientSelector(
        User $nurse,
        Branch $branch,
        ?string $search = null,
    ): Builder {
        $query = Patient::query()
            ->select([
                'patients.id',
                'patients.first_name',
                'patients.last_name',
                'patients.personal_number',
                'patients.branch_id',
                'patients.nurse_id',
            ]);

        $search = trim((string) $search);

        $companyWideSearch = mb_strlen($search) >= 2;

        if (! $companyWideSearch) {
            $query
                ->where('patients.branch_id', $branch->id)
                ->where('patients.nurse_id', $nurse->id);
        } else {
            $query->with([
                'branch:id,code,identificator,city',
                'nurse:id,title,first_name,last_name',
            ])
                ->whereHas('branch', function (Builder $query) use ($branch) {
                    $query->where('company_id', $branch->company_id);
                });
        }

        $tokens = $search !== ''
            ? (preg_split('/\s+/u', mb_strtolower($search)) ?: [])
            : [];

        foreach ($tokens as $token) {
            $pattern = '%'.AccentInsensitiveSearch::normalizeTerm($token).'%';
            $identifier = preg_replace('/[^[:alnum:]]/u', '', $token) ?: $token;
            $firstName = AccentInsensitiveSearch::expression('patients.first_name');
            $lastName = AccentInsensitiveSearch::expression('patients.last_name');

            $query->where(function (Builder $query) use ($pattern, $identifier, $firstName, $lastName) {
                $query
                    ->whereRaw("{$firstName} LIKE ?", [$pattern])
                    ->orWhereRaw("{$lastName} LIKE ?", [$pattern])
                    ->orWhereRaw(
                        "REPLACE(REPLACE(COALESCE(patients.personal_number, ''), '/', ''), ' ', '') LIKE ?",
                        ['%'.$identifier.'%'],
                    );
            });
        }

        if ($companyWideSearch) {
            $query->selectRaw(
                'CASE WHEN patients.branch_id = ? AND patients.nurse_id = ? THEN 0 ELSE 1 END AS selection_priority',
                [$branch->id, $nurse->id],
            )
                ->orderBy('selection_priority');
        }

        return $query
            ->orderBy('patients.last_name')
            ->orderBy('patients.first_name')
            ->orderBy('patients.id');
    }

    public function create(array $data): Patient
    {
        $user = auth()->user();
        $data['nurse_id'] = $user->id;
        $insuranceWasVerified = $this->verifyIncomingCoverage($data);

        return DB::transaction(function () use ($data, $insuranceWasVerified) {
            $coverageData = $data['coverage'] ?? null;
            unset($data['coverage']);

            $branch = Branch::query()->findOrFail((int) $data['branch_id']);
            $data['personal_number'] = $this->normalizePersonalNumber(
                (string) ($data['personal_number'] ?? '')
            ) ?: null;
            $this->lockPatientIdentity((int) $branch->company_id, $data['personal_number']);
            $this->assertNoPatientAssignedToAnotherNurse(
                $data['personal_number'],
                (int) $branch->company_id,
                (int) $data['nurse_id'],
            );

            if (is_array($coverageData)) {
                $coverageData['is_verified'] = $insuranceWasVerified;

                if (array_key_exists('insurance_company_id', $coverageData)) {
                    $data['insurance_company_id'] = $coverageData['insurance_company_id'];
                }
            }

            $patient = Patient::create($data);

            $this->saveCurrentCoverage($patient, $coverageData);
            $patient->load(['doctor', 'visits', 'insuranceCompany', 'latestCoverage.insuranceCompany']);

            return $patient;
        });
    }

    public function update(Patient $patient, array $data): Patient
    {
        $verificationRequested = (bool) data_get($data, 'coverage.is_verified', false);
        $insuranceWasVerified = $verificationRequested
            ? $this->verifyIncomingCoverage($data, $patient)
            : false;

        return DB::transaction(function () use (
            $patient,
            $data,
            $verificationRequested,
            $insuranceWasVerified,
        ) {
            $coverageData = $data['coverage'] ?? null;
            $legacyInsuranceCompanyWasProvided = array_key_exists('insurance_company_id', $data);
            /** @var PatientCoverage|null $currentCoverage */
            $currentCoverage = $patient->latestCoverage()->first();
            $personalNumberChanged = array_key_exists('personal_number', $data)
                && $this->normalizePersonalNumber((string) $data['personal_number'])
                    !== $this->normalizePersonalNumber((string) $patient->personal_number);
            $patientNameChanged = (
                array_key_exists('first_name', $data)
                && trim((string) $data['first_name']) !== trim((string) $patient->first_name)
            ) || (
                array_key_exists('last_name', $data)
                && trim((string) $data['last_name']) !== trim((string) $patient->last_name)
            );
            $verificationIdentityChanged = $personalNumberChanged || $patientNameChanged;

            if (array_key_exists('personal_number', $data)) {
                $data['personal_number'] = $this->normalizePersonalNumber(
                    (string) $data['personal_number']
                ) ?: null;
                $branchId = (int) ($data['branch_id'] ?? $patient->branch_id);
                $branch = Branch::query()->findOrFail($branchId);
                $this->lockPatientIdentity((int) $branch->company_id, $data['personal_number']);
                $this->assertNoPatientAssignedToAnotherNurse(
                    $data['personal_number'],
                    (int) $branch->company_id,
                    (int) ($data['nurse_id'] ?? $patient->nurse_id),
                    (int) $patient->id,
                );
            }

            unset($data['coverage']);

            if (is_array($coverageData)) {
                if (array_key_exists('insurance_company_id', $coverageData)) {
                    $data['insurance_company_id'] = $coverageData['insurance_company_id'];
                } elseif ($legacyInsuranceCompanyWasProvided) {
                    $coverageData['insurance_company_id'] = $data['insurance_company_id'];
                }
            } elseif ($legacyInsuranceCompanyWasProvided) {
                $coverageData = [
                    'insurance_company_id' => $data['insurance_company_id'],
                ];
            }

            if (is_array($coverageData)) {
                $insuranceChanged = array_key_exists('insurance_company_id', $coverageData)
                    && (int) ($coverageData['insurance_company_id'] ?? 0)
                        !== (int) ($currentCoverage?->insurance_company_id ?? 0);
                $categoryChanged = array_key_exists('category', $coverageData)
                    && (string) $coverageData['category']
                        !== (string) ($currentCoverage?->category?->value ?? $currentCoverage?->category ?? '');

                if ($verificationRequested) {
                    $coverageData['is_verified'] = $insuranceWasVerified;
                } elseif ($verificationIdentityChanged || $insuranceChanged || $categoryChanged) {
                    $coverageData['is_verified'] = false;
                } else {
                    unset($coverageData['is_verified']);
                }
            }

            if (array_key_exists('dekurz_number', $data)) {
                $incoming = (int) $data['dekurz_number'];
                $current = (int) ($patient->dekurz_number ?? 0);

                if ($incoming <= $current) {
                    unset($data['dekurz_number']);
                }
            }

            $patient->update($data);

            if ($verificationIdentityChanged && ! is_array($coverageData) && $currentCoverage) {
                $currentCoverage->update(['is_verified' => false]);
            }

            $this->saveCurrentCoverage($patient, $coverageData);
            $patient->load(['doctor', 'visits', 'insuranceCompany', 'latestCoverage.insuranceCompany']);

            return $patient;
        });
    }

    public function delete(Patient $patient): void
    {
        $patient->delete();
    }

    public function deleteManyByIds(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            Patient::whereIn('id', $ids)->delete();
        });
    }

    public function deleteManyInBranch(array $ids, Branch $branch): void
    {
        Patient::where('branch_id', $branch->id)->whereIn('id', $ids)->delete();
    }

    public function restoreManyByIds(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            $patients = Patient::withTrashed()
                ->whereIn('id', $ids)
                ->whereNotNull('deleted_at')
                ->get();

            foreach ($patients as $patient) {
                $branch = Branch::query()->findOrFail((int) $patient->branch_id);
                $personalNumber = $this->normalizePersonalNumber(
                    (string) $patient->personal_number
                ) ?: null;

                $this->lockPatientIdentity((int) $branch->company_id, $personalNumber);
                $this->assertNoPatientAssignedToAnotherNurse(
                    $personalNumber,
                    (int) $branch->company_id,
                    (int) $patient->nurse_id,
                    (int) $patient->id,
                );

                $patient->restore();
            }
        });
    }

    public function ensureAssignedToBranch(Patient $patient, Branch $branch): bool
    {
        return $patient->branch_id === $branch->id;
    }

    public function findWithRelations(int $id): ?Patient
    {
        return Patient::with(['doctor', 'visits', 'insuranceCompany', 'latestCoverage.insuranceCompany'])
            ->find($id);
    }

    private function saveCurrentCoverage(Patient $patient, ?array $coverageData): void
    {
        if ($coverageData === null) {
            if ($patient->coverages()->exists()) {
                return;
            }

            $coverageData = [
                'category' => 'domestic',
                'identification_method' => 'slovak_identifier',
                'insurance_company_id' => $patient->insurance_company_id,
                'is_verified' => false,
            ];
        }

        $coverageId = isset($coverageData['id']) ? (int) $coverageData['id'] : null;
        unset($coverageData['id']);

        $coverageData = $this->normalizeCoverageData($coverageData);

        /** @var PatientCoverage|null $coverage */
        $coverage = $coverageId
            ? $patient->coverages()->whereKey($coverageId)->first()
            : $patient->latestCoverage()->first();

        if ($coverage) {
            $coverage->update($coverageData);

            return;
        }

        $patient->coverages()->create($coverageData);
    }

    private function normalizeCoverageData(array $coverageData): array
    {
        if (isset($coverageData['member_state_code'])) {
            $coverageData['member_state_code'] = strtoupper(trim($coverageData['member_state_code']));
        }

        if (($coverageData['category'] ?? null) === 'domestic') {
            $coverageData['identification_method'] = 'slovak_identifier';
            $coverageData['member_state_code'] = null;
            $coverageData['foreign_insured_id'] = null;
            $coverageData['special_category'] = null;
        }

        if (($coverageData['category'] ?? null) === 'eu') {
            $coverageData['identification_method'] = 'foreign_triad';
            $coverageData['special_category'] = null;
        }

        return $coverageData;
    }

    private function coverageIdentityChanged($coverage, array $incoming): bool
    {
        $identityFields = [
            'insurance_company_id',
            'category',
            'identification_method',
            'member_state_code',
            'foreign_insured_id',
            'special_category',
        ];

        foreach ($identityFields as $field) {
            if (! array_key_exists($field, $incoming)) {
                continue;
            }

            $current = $coverage->{$field};
            $current = $current instanceof \BackedEnum ? $current->value : $current;

            if ((string) ($current ?? '') !== (string) ($incoming[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function normalizePersonalNumber(string $personalNumber): string
    {
        $personalNumber = trim($personalNumber);

        if (preg_match('/[[:alpha:]]/u', $personalNumber)) {
            return mb_strtoupper(
                preg_replace('/\s+/u', '', $personalNumber) ?: '',
            );
        }

        return preg_replace('/\D+/', '', $personalNumber) ?: '';
    }

    private function verifyIncomingCoverage(array $data, ?Patient $patient = null): bool
    {
        $coverage = $data['coverage'] ?? null;

        if (! is_array($coverage) || ! (bool) ($coverage['is_verified'] ?? false)) {
            return false;
        }

        $result = $this->insuranceVerification->verifyInput(
            (string) ($data['personal_number'] ?? $patient?->personal_number ?? ''),
            (string) ($data['first_name'] ?? $patient?->first_name ?? ''),
            (string) ($data['last_name'] ?? $patient?->last_name ?? ''),
            isset($coverage['insurance_company_id'])
                ? (int) $coverage['insurance_company_id']
                : $patient?->latestCoverage?->insurance_company_id,
            (string) ($coverage['category'] ?? $patient?->latestCoverage?->category?->value ?? ''),
        );

        return ($result['status'] ?? null) === 'verified';
    }

    private function lockPatientIdentity(int $companyId, ?string $personalNumber): void
    {
        if (! $personalNumber || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ["patient-company:{$companyId}:{$personalNumber}"],
        );
    }

    private function assertNoPatientAssignedToAnotherNurse(
        ?string $personalNumber,
        int $companyId,
        int $nurseId,
        ?int $exceptPatientId = null,
    ): void {
        if (! $personalNumber) {
            return;
        }

        $query = Patient::query()
            ->with('branch')
            ->whereHas('branch', function (Builder $query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->whereNotNull('nurse_id')
            ->where('nurse_id', '<>', $nurseId);

        if ($exceptPatientId) {
            $query->where('patients.id', '<>', $exceptPatientId);
        }

        if (DB::getDriverName() === 'pgsql') {
            $query->whereRaw(
                'CASE '
                ."WHEN COALESCE(personal_number, '') ~ '[[:alpha:]]' "
                ."THEN upper(regexp_replace(COALESCE(personal_number, ''), '\\s+', '', 'g')) "
                ."ELSE regexp_replace(COALESCE(personal_number, ''), '[^0-9]', '', 'g') "
                .'END = ?',
                [$personalNumber],
            );
            $duplicate = $query->first();
        } else {
            $duplicate = $query->get()->first(function (Patient $candidate) use ($personalNumber) {
                return $this->normalizePersonalNumber((string) $candidate->personal_number)
                    === $personalNumber;
            });
        }

        if (! $duplicate) {
            return;
        }

        $nurse = $duplicate->nurse_id
            ? User::withTrashed()->find($duplicate->nurse_id)
            : null;
        $workerName = $nurse
            ? trim(
                ($nurse->title ? $nurse->title.' ' : '')
                .$nurse->first_name
                .' '
                .$nurse->last_name
            )
            : 'bez priradeného pracovníka';
        $branch = $duplicate->branch;
        $branchName = $branch
            ? collect([$branch->code, $branch->city, $branch->address])->filter()->join(' – ')
            : 'bez určenej pobočky';
        $patientName = trim($duplicate->first_name.' '.$duplicate->last_name);

        $message = "Pacient {$patientName} s týmto rodným číslom je už v spoločnosti "
            ."evidovaný u pracovníka {$workerName} na pobočke {$branchName}."
            .' Požiadajte manažéra, aby pacienta presunul.';

        throw ValidationException::withMessages([
            'personal_number' => [$message],
        ]);
    }
}
