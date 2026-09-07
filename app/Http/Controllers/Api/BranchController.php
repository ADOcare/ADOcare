<?php

namespace App\Http\Controllers\Api;

use App\Http\Filters\ApiQuery;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Http\Requests\DeleteBranchRequest;
use App\Http\Requests\DeleteManyBranchesRequest;
use App\Http\Resources\BaseCollection;
use App\Http\Resources\PatientCollection;
use App\Http\Resources\DoctorCollection;
use App\Http\Resources\DoctorResource;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Patient;
use App\Models\Doctor;
use App\Models\User;
use App\Models\Role;
use App\Services\CompanyResourceUsageService;
use Illuminate\Http\Request;
use \App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $query = Branch::query();
        $results = ApiQuery::apply(request(), $query);
        return $this->success(new BaseCollection($results), 'Branches retrieved');
    }



    public function patients(Branch $branch)
    {
        $query = Patient::with(['doctor', 'visits', 'insuranceCompany'])
            ->where('branch_id', $branch->id);

        $results = ApiQuery::apply(request(), $query);
        return $this->success(new PatientCollection($results), 'Branch patients retrieved');
    }

    public function users(Branch $branch)
    {
        $usersQuery = User::query()->whereHas('branches', function ($q) use ($branch) {
            $q->where('branch_id', $branch->id);
        });

        $results = ApiQuery::apply(request(), $usersQuery);

        return $this->success(new BaseCollection($results), 'Branch users retrieved');
    }

    public function nurses(Branch $branch)
    {
        // Only users who are assigned to this branch with the "nurse" role should be returned.
        $nurseRoleId = Role::where('position', 'nurse')
            ->where('scope', 'branch')
            ->value('id');

        $nursesQuery = User::query();

        if ($nurseRoleId) {
            $nursesQuery->whereHas('branches', function ($q) use ($branch, $nurseRoleId) {
                $q->where('branch_id', $branch->id)
                    ->where('role_id', $nurseRoleId);
            });
        } else {
            // Fallback: no nurse role defined, return empty set.
            $nursesQuery->whereRaw('0 = 1');
        }


        $results = ApiQuery::apply(request(), $nursesQuery);

        return $this->success(new BaseCollection($results), 'Branch nurses retrieved');
    }


    public function store(StoreBranchRequest $request)
    {
        $data = $request->validated();
        // if company_id is not provided, set it to the company of the authenticated user
        if (!isset($data['company_id'])) {
            $data['company_id'] = auth()->user()->company_id;
        }

        $company = Company::query()->find((int) $data['company_id']);

        $item = $company
            ? app(CompanyResourceUsageService::class)
                ->createWithinLimit($company, 'branches', fn () => Branch::create($data))
            : Branch::create($data);

        return $this->success($item, 'Created', Response::HTTP_CREATED);
    }

    public function show(Branch $branch)
    {
        return $this->success($branch, 'Branch retrieved');
    }

    public function update(UpdateBranchRequest $request, Branch $branch)
    {
        $branch->update($request->all());
        return $this->success($branch, 'Updated');
    }

    public function destroyMany(DeleteManyBranchesRequest $request)
    {
        $ids = $request->input('ids');
        Branch::whereIn('id', $ids)->delete();
        return $this->success(null, 'Branches deleted', Response::HTTP_NO_CONTENT);
    }

    public function destroy(DeleteBranchRequest $request, Branch $branch)
    {
        $branch->delete();
        return $this->success(null, 'Deleted', Response::HTTP_NO_CONTENT);
    }
}
