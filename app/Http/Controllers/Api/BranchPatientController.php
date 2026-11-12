<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Filters\ApiQuery;
use App\Http\Requests\BranchPatientStoreRequest;
use App\Http\Resources\BaseCollection;
use App\Http\Resources\PatientCollection;
use App\Http\Resources\PatientResource;
use App\Http\Resources\PatientTableResource;
use App\Models\Branch;
use App\Models\Patient;
use App\Services\PatientService;
use Illuminate\Http\Request;

class BranchPatientController extends Controller
{
    private PatientService $service;

    public function __construct(PatientService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request, Branch $branch)
    {
        $user = $request->user();

        $query = $this->service->queryForUserBranch($user, $branch);

        $results = ApiQuery::apply(
            $request,
            $query,
            searchable: [
                'first_name',
                'last_name',
                'personal_number',
            ],
            allowedFilters: ['sex', 'nurse_id'],
            defaults: ['sort' => 'last_name'],
            allowTrashed: false,
        );

        $collection = $request->boolean('table')
            ? new BaseCollection($results, PatientTableResource::class)
            : new PatientCollection($results);

        return $this->success($collection, 'Patients retrieved');
    }

    public function selector(Request $request, Branch $branch)
    {
        $user = $request->user();

        abort_unless(
            $user
            && $user->isInCompany((int) $branch->company_id)
            && $user->isInBranch((int) $branch->id),
            403,
        );

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));
        $patients = $this->service
            ->queryForPatientSelector($user, $branch, $search)
            ->cursorPaginate(20);

        $items = $patients->getCollection()->map(function (Patient $patient) use ($user, $branch) {
            $selectable = (int) $patient->branch_id === (int) $branch->id
                && (int) $patient->nurse_id === (int) $user->id;
            $branchLabel = $patient->relationLoaded('branch') && $patient->branch
                ? collect([
                    $patient->branch->code ?: $patient->branch->identificator,
                    $patient->branch->city,
                ])->filter()->implode(' · ')
                : null;
            $nurseLabel = $patient->relationLoaded('nurse') && $patient->nurse
                ? trim(collect([
                    $patient->nurse->title,
                    $patient->nurse->first_name,
                    $patient->nurse->last_name,
                ])->filter()->implode(' '))
                : null;

            return [
                'id' => (int) $patient->id,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'personal_number' => $patient->personal_number,
                'branch_id' => $patient->branch_id ? (int) $patient->branch_id : null,
                'nurse_id' => $patient->nurse_id ? (int) $patient->nurse_id : null,
                'branch_label' => $branchLabel,
                'nurse_label' => $nurseLabel,
                'selectable' => $selectable,
            ];
        })->values();

        return $this->success([
            'items' => $items,
            'next_cursor' => $patients->nextCursor()?->encode(),
            'has_more' => $patients->hasMorePages(),
        ], 'Patient selector results retrieved');
    }

    public function store(BranchPatientStoreRequest $request, Branch $branch)
    {
        $user = $request->user();
        abort_unless(
            $user && (
                $user->hasGlobalRole('super-admin')
                || (
                    $user->isInCompany((int) $branch->company_id)
                    && (
                        $user->isInBranch((int) $branch->id)
                        || $user->hasGlobalRole('manager')
                        || $user->hasGlobalRole('admin')
                    )
                )
            ),
            403,
        );

        $data = $request->validated();
        $data['branch_id'] = $branch->id;

        $patient = $this->service->create($data);

        return $this->success(new PatientResource($patient), 'Patient created', 201);
    }
}
