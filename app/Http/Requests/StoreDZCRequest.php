<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Branch;

class StoreDZCRequest extends FormRequest
{
    public function authorize(): bool
    {
        $branchId = $this->input('branch_id');
        if (! $branchId) return false;

        $branch = Branch::find($branchId);
        if (! $branch) return false;

        return $this->user()->isInBranch($branch->id) || $this->user()->hasGlobalRole('manager');
    }

    public function rules(): array
    {
        return [
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
            'branch_id' => 'required|exists:branches,id',
            'car_id' => 'nullable|integer|min:1',
        ];
    }
}
