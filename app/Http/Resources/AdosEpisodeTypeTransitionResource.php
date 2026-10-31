<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Presents one company-configured episode type transition.
 */
class AdosEpisodeTypeTransitionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'from_episode_type_id' => $this->from_episode_type_id,
            'to_episode_type_id' => $this->to_episode_type_id,
            'from_type' => $this->whenLoaded('fromType', fn () => new AdosEpisodeTypeResource($this->fromType)),
            'to_type' => $this->whenLoaded('toType', fn () => new AdosEpisodeTypeResource($this->toType)),
        ];
    }
}
