<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates creation of a company episode type.
 */
class StoreAdosEpisodeTypeRequest extends FormRequest
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
        $company = $this->route('company');
        $companyId = $company instanceof Company ? $company->id : $this->user()?->company_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('ados_episode_types', 'code')->where('company_id', $companyId),
            ],
            'description' => ['nullable', 'string'],
            'default_duration_days' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
