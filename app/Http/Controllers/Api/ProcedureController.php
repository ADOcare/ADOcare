<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Filters\ApiQuery;
use App\Http\Requests\DestroyManyProcedureRequest;
use App\Http\Requests\ProcedureStoreRequest;
use App\Http\Requests\ProcedureUpdateRequest;
use App\Http\Resources\BaseCollection;
use App\Models\Procedure;
use App\Services\ProcedureRoleService;
use App\Services\ProcedureService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProcedureController extends Controller
{
    private ProcedureService $service;

    public function __construct(
        ProcedureService $service,
        private ProcedureRoleService $procedureRoles,
    ) {
        $this->service = $service;
    }

    /**
     * List procedures
     *
     * @group Procedures
     *
     * @queryParam per_page int The number of items per page. Example: 15
     *
     * @response 200 {"data":[{"id":1,"code":"X123","description":"Test"}],"meta":{"total":1}}
     */
    public function index(Request $request)
    {
        $query = Procedure::query()->with('roles');
        $branchId = $request->header('X-Current-Branch');
        $this->procedureRoles->scopeForUser(
            $query,
            $request->user(),
            is_numeric($branchId) ? (int) $branchId : null,
        );
        $result = ApiQuery::apply(
            $request,
            $query,
            searchable: ['code', 'description'],
            allowedFilters: [],
            defaults: [
                'sort' => 'code',
                'with' => 'insuranceCompaniesPricesMinimal',

            ]
        );

        return $this->success(new BaseCollection($result), 'Procedures retrieved');
    }

    /**
     * Create a procedure
     *
     * @group Procedures
     *
     * @bodyParam code string required Procedure code. Example: "X123"
     * @bodyParam description string Procedure description. Example: "Example"
     *
     * @response 201 {"id":1,"code":"X123","description":"Example"}
     */
    public function store(ProcedureStoreRequest $request)
    {
        $data = $request->validated();
        $companyId = $request->user()?->company_id;

        $procedure = $this->service->createWithPrices($data, $companyId);

        return $this->success($procedure, 'Created', Response::HTTP_CREATED);
    }

    /**
     * Get a procedure
     *
     * @group Procedures
     *
     * @urlParam procedure int required Procedure ID. Example: 1
     *
     * @response 200 {"id":1,"code":"X123","description":"Example"}
     */
    public function show(Procedure $procedure)
    {
        $procedure->load(['insuranceCompaniesPricesMinimal', 'roles']);

        return $this->success($procedure, 'Procedure retrieved');
    }

    /**
     * Update a procedure
     *
     * @group Procedures
     *
     * @urlParam procedure int required Procedure ID. Example: 1
     *
     * @bodyParam code string Procedure code. Example: "X123"
     * @bodyParam description string Procedure description. Example: "Updated"
     *
     * @response 200 {"id":1,"code":"X123","description":"Updated"}
     */
    public function update(ProcedureUpdateRequest $request, Procedure $procedure)
    {
        $data = $request->validated();
        $companyId = $request->user()?->company_id;

        $procedure = $this->service->updateWithPrices($procedure, $data, $companyId);

        return $this->success($procedure, 'Updated');
    }

    /**
     * Delete a procedure
     *
     * @group Procedures
     *
     * @urlParam procedure int required Procedure ID. Example: 1
     *
     * @response 204 {}
     */
    public function destroy(Procedure $procedure)
    {
        $this->service->destroy($procedure);

        return $this->success(null, 'Deleted', Response::HTTP_NO_CONTENT);
    }

    /**
     * Bulk delete procedures
     *
     * @group Procedures
     *
     * @bodyParam ids array required Array of procedure IDs to delete. Example: [1,2,3]
     *
     * @response 200 {"success":true}
     */
    public function destroyMany(DestroyManyProcedureRequest $request)
    {
        $ids = $request->validated()['ids'];

        $this->service->destroyMany($ids);

        return $this->success(null, 'Procedures deleted');
    }
}
