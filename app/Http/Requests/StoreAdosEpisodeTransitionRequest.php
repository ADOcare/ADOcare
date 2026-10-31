<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates creation of a company episode-type transition.
 */
class StoreAdosEpisodeTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');
        $user = $this->user();
        $companyId = $company instanceof Company ? $company->id : $user?->company_id;

        return $companyId !== null
            && $user !== null
            && ($user->hasGlobalRole('superadmin')
                || ($user->hasGlobalRole('manager') && (int) $user->company_id === (int) $companyId));
    }

    public function rules(): array
    {
        return [
            'from_episode_type_id' => ['required', 'integer', 'exists:ados_episode_types,id'],
            'to_episode_type_id' => [
                'required',
                'integer',
                'different:from_episode_type_id',
                'exists:ados_episode_types,id',
            ],
        ];
    }
}
