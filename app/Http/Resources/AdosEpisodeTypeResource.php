<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Presents a configurable ADOS episode type.
 */
class AdosEpisodeTypeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'default_duration_days' => $this->default_duration_days,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
