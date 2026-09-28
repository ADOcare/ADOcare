<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\EoverenieInsuranceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientInsuranceCheckController extends Controller
{
    public function __construct(private EoverenieInsuranceService $service)
    {
    }

    public function store(Patient $patient): JsonResponse
    {
        $patient->loadMissing('latestCoverage.insuranceCompany');
        $coverage = $patient->latestCoverage;
        $result = $this->service->verify($patient, $coverage);

        $definitiveStatuses = [
            'verified',
            'mismatch',
            'not_insured',
            'duplicity',
            'identity_mismatch',
        ];

        if ($coverage && in_array($result['status'], $definitiveStatuses, true)) {
            $coverage->update([
                'is_verified' => $result['status'] === 'verified',
            ]);
        }

        $result['is_verified'] = $coverage?->fresh()->is_verified ?? false;

        return $this->success($result, 'Overenie poistného vzťahu bolo dokončené.');
    }

    public function storeForm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'personal_number' => ['required', 'string', 'max:20'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'insurance_company_id' => ['required', 'integer', 'exists:insurance_companies,id'],
            'regime' => ['required', Rule::in(['domestic', 'eu', 'special'])],
        ]);

        $result = $this->service->verifyInput(
            $data['personal_number'],
            $data['first_name'],
            $data['last_name'],
            (int) $data['insurance_company_id'],
            $data['regime'],
        );

        return $this->success($result, 'Overenie poistného vzťahu bolo dokončené.');
    }
}
