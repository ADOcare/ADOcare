<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PatientTableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $data = [
            'id' => (int) $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'personal_number' => $this->personal_number,
            'death_date' => $this->death_date,
            'branch_id' => $this->branch_id ? (int) $this->branch_id : null,
            'nurse_id' => $this->nurse_id ? (int) $this->nurse_id : null,
            'open_patient_audit_issues_count' => (int) ($this->open_patient_audit_issues_count ?? 0),
        ];

        if ($this->relationLoaded('nurse')) {
            $data['nurse'] = $this->nurse
                ? [
                    'id' => (int) $this->nurse->id,
                    'title' => $this->nurse->title,
                    'first_name' => $this->nurse->first_name,
                    'last_name' => $this->nurse->last_name,
                ]
                : null;
        }

        if ($this->relationLoaded('branch')) {
            $data['branch'] = $this->branch
                ? [
                    'id' => (int) $this->branch->id,
                    'code' => $this->branch->code,
                    'identificator' => $this->branch->identificator,
                    'address' => $this->branch->address,
                    'city' => $this->branch->city,
                ]
                : null;
        }

        return $data;
    }
}
