<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the dates and type selected when starting an episode.
 */
class StartAdosEpisodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'episode_type_id' => ['required', 'integer', 'exists:ados_episode_types,id'],
            'started_at' => ['required', 'date_format:Y-m-d'],
            'expected_end_at' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:started_at'],
        ];
    }
}
