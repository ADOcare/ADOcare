<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\NurseDiagnosis;
use App\Models\Procedure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClinicalSuggestionController extends Controller
{
    public function diagnoses(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = $this->authorizedCompanyId($request, (int) $validated['branch_id']);
        $usage = $this->usageQuery($companyId, 'diagnosis');

        $query = Diagnosis::query()
            ->leftJoinSub($usage, 'usage', 'usage.item_id', '=', 'diagnoses.id')
            ->select(['diagnoses.id', 'diagnoses.code', 'diagnoses.description'])
            ->selectRaw('COALESCE(usage.usage_count, 0) as usage_count');

        $this->applySearch($query, $validated['q'] ?? null);

        $results = $query
            ->orderByDesc('usage_count')
            ->orderBy('diagnoses.code')
            ->limit((int) ($validated['limit'] ?? 25))
            ->get();

        return $this->success($results, 'Diagnosis suggestions retrieved');
    }

    public function procedures(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'diagnosis_id' => ['nullable', 'integer', 'exists:diagnoses,id'],
            'diagnosis_ids' => ['nullable', 'array', 'max:100'],
            'diagnosis_ids.*' => ['integer', 'distinct', 'exists:diagnoses,id'],
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = $this->authorizedCompanyId($request, (int) $validated['branch_id']);
        $diagnosisIds = collect($validated['diagnosis_ids'] ?? [])
            ->when(
                isset($validated['diagnosis_id']),
                fn ($ids) => $ids->push((int) $validated['diagnosis_id']),
            )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $usage = $this->usageQuery($companyId, 'procedure', $diagnosisIds);

        $query = Procedure::query()
            ->leftJoinSub($usage, 'usage', 'usage.item_id', '=', 'procedures.id')
            ->select(['procedures.id', 'procedures.code', 'procedures.description'])
            ->selectRaw('COALESCE(usage.usage_count, 0) as usage_count');

        $this->applySearch($query, $validated['q'] ?? null);

        $results = $query
            ->orderByDesc('usage_count')
            ->orderBy('procedures.code')
            ->limit((int) ($validated['limit'] ?? 25))
            ->get();

        return $this->success($results, 'Procedure suggestions retrieved');
    }

    public function nurseDiagnoses(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = $this->authorizedCompanyId($request, (int) $validated['branch_id']);
        $usage = $this->usageQuery($companyId, 'nurse_diagnosis');

        $query = NurseDiagnosis::query()
            ->leftJoinSub($usage, 'usage', 'usage.item_id', '=', 'nurse_diagnoses.id')
            ->select(['nurse_diagnoses.id', 'nurse_diagnoses.code', 'nurse_diagnoses.description'])
            ->selectRaw('COALESCE(usage.usage_count, 0) as usage_count');

        $this->applySearch($query, $validated['q'] ?? null);

        $results = $query
            ->orderByDesc('usage_count')
            ->orderBy('nurse_diagnoses.code')
            ->limit((int) ($validated['limit'] ?? 25))
            ->get();

        return $this->success($results, 'Nurse diagnosis suggestions retrieved');
    }

    private function usageQuery(int $companyId, string $type, array $contextIds = [])
    {
        return DB::table('clinical_suggestion_usages')
            ->where('company_id', $companyId)
            ->where('suggestion_type', $type)
            ->when(
                $contextIds !== [],
                fn ($query) => $query->whereIn('context_id', $contextIds),
                fn ($query) => $query->where('context_id', 0),
            )
            ->groupBy('item_id')
            ->select('item_id')
            ->selectRaw('SUM(usage_count) as usage_count');
    }

    private function authorizedCompanyId(Request $request, int $branchId): int
    {
        $branch = Branch::query()->findOrFail($branchId);
        $user = $request->user();
        $companyId = (int) $branch->getAttribute('company_id');
        $resolvedBranchId = (int) $branch->getKey();

        abort_unless(
            $user && ($user->isInCompany($companyId) || $user->isInBranch($resolvedBranchId)),
            403,
        );

        return $companyId;
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        $like = '%'.mb_strtolower($search).'%';
        $table = $query->getModel()->getTable();

        $query->where(function (Builder $query) use ($like, $table) {
            $query->whereRaw("LOWER({$table}.code) LIKE ?", [$like])
                ->orWhereRaw("LOWER({$table}.description) LIKE ?", [$like]);
        });
    }
}
