<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Diagnosis;
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
        $usage = DB::table('patient_points as points')
            ->join('branches', 'branches.id', '=', 'points.branch_id')
            ->where('branches.company_id', $companyId)
            ->whereNotNull('points.diagnosis_id')
            ->groupBy('points.diagnosis_id')
            ->select('points.diagnosis_id')
            ->selectRaw('SUM(COALESCE(points.quantity, 1)) as usage_count');

        $query = Diagnosis::query()
            ->leftJoinSub($usage, 'usage', 'usage.diagnosis_id', '=', 'diagnoses.id')
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
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = $this->authorizedCompanyId($request, (int) $validated['branch_id']);
        $diagnosisId = isset($validated['diagnosis_id'])
            ? (int) $validated['diagnosis_id']
            : null;

        $usage = DB::table('patient_points as points')
            ->join('branches', 'branches.id', '=', 'points.branch_id')
            ->where('branches.company_id', $companyId)
            ->whereNotNull('points.procedure_id')
            ->when(
                $diagnosisId !== null,
                fn ($query) => $query->where('points.diagnosis_id', $diagnosisId),
            )
            ->groupBy('points.procedure_id')
            ->select('points.procedure_id')
            ->selectRaw('SUM(COALESCE(points.quantity, 1)) as usage_count');

        $query = Procedure::query()
            ->leftJoinSub($usage, 'usage', 'usage.procedure_id', '=', 'procedures.id')
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

        $like = '%' . mb_strtolower($search) . '%';
        $table = $query->getModel()->getTable();

        $query->where(function (Builder $query) use ($like, $table) {
            $query->whereRaw("LOWER({$table}.code) LIKE ?", [$like])
                ->orWhereRaw("LOWER({$table}.description) LIKE ?", [$like]);
        });
    }
}
