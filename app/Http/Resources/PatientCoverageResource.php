<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PatientCoverageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category?->value ?? $this->category,
            'identification_method' => $this->identification_method?->value ?? $this->identification_method,
            'insurance_company_id' => $this->insurance_company_id,
            'member_state_code' => $this->member_state_code,
            'foreign_insured_id' => $this->foreign_insured_id,
            'special_category' => $this->special_category?->value ?? $this->special_category,
            'is_verified' => $this->is_verified,
            'insurance_company' => $this->whenLoaded('insuranceCompany', function () {
                return $this->insuranceCompany
                    ? new InsuranceCompanyResource($this->insuranceCompany)
                    : null;
            }),
        ];
    }
}
