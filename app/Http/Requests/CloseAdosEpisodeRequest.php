<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the effective end date when closing an episode.
 */
class CloseAdosEpisodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'ended_at' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
