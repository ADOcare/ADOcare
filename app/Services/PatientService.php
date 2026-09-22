<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Document;
use App\Models\Patient;
use App\Models\User;
use App\Enums\PatientCoverageRegime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PatientService
{
    public function queryForUserBranch(User $nurse, Branch $branch): Builder
    {
        return Patient::with(['doctor', 'visits', 'insuranceCompany', 'latestCoverage.insuranceCompany'])
            ->where('nurse_id', $nurse->id)
            ->where('branch_id', $branch->id);
    }

    // FUnction to check if branch_id or nurse_id are being set by non-manager user
    // public function checkManagerProtectedFields(array $data): void
    // {
    //     // branch_id and nurse_id are manager protected. If user is not manager, throw error
    //     $user = auth()->user();
    //     if (($data['branch_id'] || $data['nurse_id']) && !$user->hasRole('manager')) {
    //         throw new \Exception("Unauthorized to set branch_id or nurse_id");
    //     }
    // }

    public function create(array $data): Patient
    {
        // $this->checkManagerProtectedFields($data);

        $user = auth()->user();
        $data['nurse_id'] = $user->id;

        return DB::transaction(function () use ($data) {
            $coverageData = $data['coverage'] ?? null;
            unset($data['coverage']);

            if (is_array($coverageData) && array_key_exists('insurance_company_id', $coverageData)) {
                $data['insurance_company_id'] = $coverageData['insurance_company_id'];
            }

            $patient = Patient::create($data);

            $this->saveCurrentCoverage($patient, $coverageData);
            $patient->load(['doctor', 'visits', 'insuranceCompany', 'latestCoverage.insuranceCompany']);

            return $patient;
        });
    }

    public function update(Patient $patient, array $data): Patient
    {

        // $this->checkManagerProtectedFields($data);

        return DB::transaction(function () use ($patient, $data) {
            $coverageData = $data['coverage'] ?? null;
            $legacyInsuranceCompanyWasProvided = array_key_exists('insurance_company_id', $data);
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

            if (array_key_exists('dekurz_number', $data)) {
                $incoming = (int) $data['dekurz_number'];
                $current = (int) ($patient->dekurz_number ?? 0);

                if ($incoming <= $current) {
                    unset($data['dekurz_number']);
                }
            }

            $patient->update($data);
            $this->saveCurrentCoverage($patient, $coverageData);
            $patient->load(['doctor', 'visits', 'insuranceCompany', 'latestCoverage.insuranceCompany']);

            return $patient;
        });
    }


    public function delete(Patient $patient): void
    {
        $patient->delete();
    }

    public function deleteManyByIds(
        array $ids,
        bool $deletePatientPoints = false,
        bool $deletePatientDocuments = false,
    ): void
    {
        DB::transaction(function () use ($ids, $deletePatientPoints, $deletePatientDocuments) {
            if ($deletePatientPoints) {
                // Delete all patient_points for the patients being deleted
                \App\Models\PatientPoint::whereIn('patient_id', $ids)->delete();
            }

            if ($deletePatientDocuments) {
                Document::whereIn('patient_id', $ids)->delete();
            }

            Patient::whereIn('id', $ids)->delete();
        });
    }

    public function deleteManyInBranch(array $ids, Branch $branch): void
    {
        Patient::where('branch_id', $branch->id)->whereIn('id', $ids)->delete();
    }

    public function ensureAssignedToBranch(Patient $patient, Branch $branch): bool
    {
        return $patient->branch_id === $branch->id;
    }

    public function findWithRelations(int $id): ?Patient
    {
        return Patient::with(['doctor', 'visits', 'insuranceCompany', 'latestCoverage.insuranceCompany'])->find($id);
    }

    private function saveCurrentCoverage(Patient $patient, ?array $coverageData): void
    {
        if ($coverageData === null) {
            if ($patient->coverages()->exists()) {
                return;
            }

            $coverageData = [
                'regime' => PatientCoverageRegime::UNCLASSIFIED->value,
                'insurance_company_id' => $patient->insurance_company_id,
                'valid_from' => null,
                'is_verified' => true,
            ];
        }

        $coverageId = isset($coverageData['id']) ? (int) $coverageData['id'] : null;
        unset($coverageData['id']);

        $coverageData = $this->normalizeCoverageData($coverageData);

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

        $regime = $coverageData['regime'] ?? null;

        if ($regime === PatientCoverageRegime::DOMESTIC->value) {
            $coverageData['member_state_code'] = null;
            $coverageData['foreign_insured_id'] = null;
            $coverageData['special_category'] = null;
        }

        if ($regime === PatientCoverageRegime::EU->value) {
            $coverageData['special_category'] = null;
        }

        return $coverageData;
    }
}
