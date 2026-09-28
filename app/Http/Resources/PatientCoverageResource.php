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
            'category' => $this->category?->value ?? $this->category,
            'identification_method' => $this->identification_method?->value ?? $this->identification_method,
            'insurance_company_id' => $this->insurance_company_id,
            'member_state_code' => $this->member_state_code,
            'foreign_insured_id' => $this->foreign_insured_id,
            'special_category' => $this->special_category?->value ?? $this->special_category,
            'other_subtype' => $this->other_subtype,
            'legal_basis' => $this->legal_basis,
            'entitlement_confirmed' => $this->entitlement_confirmed,
            'document_registered' => $this->document_registered,
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
