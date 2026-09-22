<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PatientCoverageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'regime' => $this->regime?->value ?? $this->regime,
            'insurance_company_id' => $this->insurance_company_id,
            'member_state_code' => $this->member_state_code,
            'foreign_insured_id' => $this->foreign_insured_id,
            'special_category' => $this->special_category?->value ?? $this->special_category,
            'entitlement_document_type' => $this->entitlement_document_type,
            'entitlement_document_number' => $this->entitlement_document_number,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'is_verified' => $this->is_verified,
            'insurance_company' => $this->whenLoaded('insuranceCompany', function () {
                return $this->insuranceCompany
                    ? new InsuranceCompanyResource($this->insuranceCompany)
                    : null;
            }),
        ];
    }
}
