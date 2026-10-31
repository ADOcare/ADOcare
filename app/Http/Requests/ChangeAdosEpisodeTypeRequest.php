<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a requested episode type change.
 */
class ChangeAdosEpisodeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'episode_type_id' => ['required', 'integer', 'exists:ados_episode_types,id'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
