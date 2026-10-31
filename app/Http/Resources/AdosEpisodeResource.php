<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Presents episode lifecycle, current type and history to the API.
 */
class AdosEpisodeResource extends JsonResource
{
    public function toArray($request): array
    {
        $today = now()->toDateString();
        $expectedEndPassed = $this->status === 'active'
            && $this->expected_end_at !== null
            && $this->expected_end_at->toDateString() < $today;

        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'branch_id' => $this->branch_id,
            'company_id' => $this->company_id,
            'episode_type_id' => $this->episode_type_id,
            'status' => $this->status,
            'started_at' => $this->started_at?->toDateString(),
            'expected_end_at' => $this->expected_end_at?->toDateString(),
            'ended_at' => $this->ended_at?->toDateString(),
            'created_by' => $this->created_by,
            'ended_by' => $this->ended_by,
            'expected_end_passed' => $expectedEndPassed,
            'type' => $this->whenLoaded('type', fn () => new AdosEpisodeTypeResource($this->type)),
            'type_history' => AdosEpisodeTypeHistoryResource::collection($this->whenLoaded('typeHistory')),
            'available_transitions' => $this->availableTransitions(),
        ];
    }

    /**
     * Return configured active destinations for the current type.
     */
    private function availableTransitions(): array
    {
        if ($this->status !== 'active' || ! $this->relationLoaded('type') || ! $this->type) {
            return [];
        }

        return $this->type->outgoingTransitions
            ->filter(fn ($transition) => $transition->toType?->is_active)
            ->map(fn ($transition) => [
                'id' => $transition->id,
                'episode_type_id' => $transition->toType->id,
                'name' => $transition->toType->name,
                'code' => $transition->toType->code,
            ])
            ->values()
            ->all();
    }
}
