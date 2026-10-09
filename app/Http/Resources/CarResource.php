<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CarResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'evc' => $this->evc,
            'vin' => $this->vin,
            'model' => $this->model,
            'owner_name' => $this->owner_name,
            'fuel_consumption_l_per_100km' => $this->fuel_consumption_l_per_100km,
            'company_id' => $this->company_id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', function () {
                return new UserResource($this->user);
            }),
            'user_exists' => $this->when($this->relationLoaded('user') ?: $this->user()->exists(), true),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
