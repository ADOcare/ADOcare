<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PatientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            // columns
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'title' => $this->title,
            'personal_number' => $this->personal_number,
            'sex' => $this->sex,
            'contact' => $this->contact,
            'branch_id' => $this->branch_id,
            'doctor_id' => $this->doctor_id,
            'nurse_id' => $this->nurse_id,
            'insurance_company_id' => $this->insurance_company_id,
            'address' => $this->address,
            'city' => $this->city,
            'zip' => $this->zip,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'reference_date' => $this->reference_date,
            'death_date' => $this->death_date,
            'dekurz_number' => $this->dekurz_number,

            // relations

            'nurse' => $this->whenLoaded('nurse', function () {
                return $this->nurse ? new UserResource($this->nurse) : null;
            }),

            'doctor' => $this->whenLoaded('doctor', function () {
                return new DoctorResource($this->doctor);
            }),

            'insurance_company' => $this->whenLoaded('insuranceCompany', function () {
                return new InsuranceCompanyResource($this->insuranceCompany);
            }),

            'coverage' => $this->whenLoaded('latestCoverage', function () {
                return $this->latestCoverage
                    ? new PatientCoverageResource($this->latestCoverage)
                    : null;
            }),

            'visits' => $this->whenLoaded('visits', function () {
                return VisitResource::collection($this->visits);
            }),

            'branch' => $this->whenLoaded('branch', function () {
                return new BranchResource($this->branch);
            }),

            // counts + exists flags
            'visits_count' => $this->whenCounted('visits'),
            'doctor_exists' => $this->when($this->relationLoaded('doctor') ?: $this->doctor()->exists(), true),
            'visits_exists' => $this->when($this->relationLoaded('visits') ?: $this->visits()->exists(), true),
            'insurance_company_exists' => $this->when($this->relationLoaded('insuranceCompany') ?: $this->insuranceCompany()->exists(), true),
        ];
    }
}
