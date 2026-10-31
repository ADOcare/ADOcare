<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Presents one half-open [valid_from, valid_to) date range in an episode's type history.
 */
class AdosEpisodeTypeHistoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'episode_type_id' => $this->episode_type_id,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'changed_by' => $this->changed_by,
            'type' => $this->whenLoaded('type', fn () => new AdosEpisodeTypeResource($this->type)),
        ];
    }
}
