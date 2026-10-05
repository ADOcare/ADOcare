<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Filters\ApiQuery;
use App\Http\Requests\BranchPatientStoreRequest;
use App\Http\Requests\BranchPatientUpdateRequest;
use App\Http\Resources\PatientCollection;
use App\Http\Resources\PatientResource;
use App\Services\PatientService;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Models\Patient;
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

        if ($request->boolean('only_dead')) {
            $query->withTrashed()->whereNotNull('patients.death_date');
        } elseif ($request->boolean('only_deleted')) {
            $query->withTrashed()->whereNotNull('patients.deleted_at');
        } elseif ($request->boolean('dead_or_deleted')) {
            $query->withTrashed()->where(function ($q) {
                $q->whereNotNull('patients.deleted_at')
                    ->orWhereNotNull('patients.death_date');
            });
        } else {
            $query->whereNull('patients.death_date');
        }

        $results = ApiQuery::apply(
            $request,
            $query,
            searchable: [
                'first_name',
                'last_name',
                'personal_number',
            ],
            allowedFilters: ['sex', 'nurse_id'],
            defaults: ['sort' => 'last_name']
        );

        return $this->success(new PatientCollection($results), 'Patients retrieved');
    }

    public function store(BranchPatientStoreRequest $request, Branch $branch)
    {
        $data = $request->validated();
        $data['branch_id'] = $branch->id;

        $patient = $this->service->create($data);

        return $this->success(new PatientResource($patient), 'Patient created', 201);
    }
}
