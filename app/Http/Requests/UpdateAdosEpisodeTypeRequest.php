<?php

namespace App\Http\Requests;

use App\Models\AdosEpisodeType;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates editable fields for a company episode type.
 */
class UpdateAdosEpisodeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');
        $type = $this->route('episodeType');
        $user = $this->user();
        $companyId = $company instanceof Company ? $company->id : $user?->company_id;

        return $companyId !== null
            && $type instanceof AdosEpisodeType
            && (int) $type->company_id === (int) $companyId
            && $user !== null
            && ($user->hasGlobalRole('superadmin')
                || ($user->hasGlobalRole('manager') && (int) $user->company_id === (int) $companyId));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'default_duration_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
