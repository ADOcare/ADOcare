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
