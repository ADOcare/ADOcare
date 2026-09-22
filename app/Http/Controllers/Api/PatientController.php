<?php
namespace App\Http\Controllers\Api;

use App\Http\Filters\ApiQuery;
use App\Http\Requests\PatientDeleteManyRequest;
use App\Http\Requests\PatientPointIndexRequest;
use App\Http\Requests\PatientStoreRequest;
use App\Http\Requests\PatientUpdateRequest;
use App\Http\Resources\BaseCollection;
use App\Http\Resources\PatientCollection;
use App\Http\Resources\PatientResource;
use App\Models\Diagnosis;
use App\Models\Document;
use App\Models\Patient;
use App\Models\PatientPoint;
use App\Models\Procedure;
use App\Http\Controllers\Controller;
use App\Services\PatientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    private PatientService $service;

    public function __construct(PatientService $service)
    {
        $this->service = $service;
    }



    /**
     * List patients for a branch.
     *
     * @group Patients
     * @queryParam branch_id int required Branch ID to filter patients. Example: 1
     * @queryParam per_page int The number of items per page. Example: 15
     * @response 200 {"data": [{"id":1, "first_name":"John", "last_name":"Doe"}], "meta": {"total":1}}
     */
    public function index(Request $request): JsonResponse
    {
        $query = Patient::with(['latestCoverage.insuranceCompany']);

        $results = ApiQuery::apply(
            $request,
            $query,
            searchable: ['first_name', 'last_name', 'personal_number'],
            allowedFilters: ['sex'],
            defaults: ['sort' => 'last_name']
        );

        return $this->success(new PatientCollection($results), 'Pacienti boli uspesne nacitani.');
    }


    /**
     * Create a patient.
     *
     * @group Patients
     * @bodyParam first_name string required Example: "John"
     * @bodyParam last_name string required Example: "Doe"
     * @bodyParam branch_id int required Branch ID. Example: 1
     * @response 201 {"data": {"id":1, "first_name":"John", "last_name":"Doe"}}
     */
    public function store(PatientStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $patient = $this->service->create($data);

        return $this->success(new PatientResource($patient), 'Pacient bol uspesne vytvoreny.', 201);
    }

    /**
     * Get patient detail.
     *
     * @group Patients
     * @urlParam patient int required Patient ID. Example: 1
     * @response 200 {"data": {"id":1, "first_name":"John", "last_name":"Doe"}}
     */
    public function show(Patient $patient): JsonResponse
    {
        $patient->loadMissing(['doctor', 'visits', 'insuranceCompany', 'latestCoverage.insuranceCompany']);

        return $this->success(new PatientResource($patient), 'Pacient bol uspesne nacitany.');
    }


    /**
     * Update patient detail.
     *
     * @group Patients
     * @urlParam patient int required Patient ID. Example: 1
     * @bodyParam first_name string Example: "Jane"
     * @bodyParam last_name string Example: "Doe"
     * @response 200 {"data": {"id":1, "first_name":"Jane", "last_name":"Doe"}}
     */
    public function update(PatientUpdateRequest $request, Patient $patient): JsonResponse
    {
        $data = $request->validated();

        $patient = $this->service->update($patient, $data);

        return $this->success(new PatientResource($patient), 'Pacient bol uspesne aktualizovany.');
    }



    /**
     * Delete a patient.
     *
     * @group Patients
     * @urlParam patient int required Patient ID. Example: 1
     * @response 200 {"success":true}
     */
    public function destroy(Patient $patient): JsonResponse
    {
        $this->service->delete($patient);

        return $this->success(null, 'Pacient bol uspesne odstraneny.');
    }

    /**
     * Delete multiple patients.
     *
     * @group Patients
     * @bodyParam ids array required Array of patient IDs to delete. Example: [1,2,3]
     * @bodyParam delete_patient_points boolean optional Also delete patient points. Example: true
     * @bodyParam delete_patient_documents boolean optional Also delete patient documents. Example: true
     * @response 200 {"success":true}
     */
    public function destroyMany(PatientDeleteManyRequest $request): JsonResponse
    {
        $this->service->deleteManyByIds(
            $request->input('ids'),
            $request->boolean('delete_patient_points', false),
            $request->boolean('delete_patient_documents', false),
        );

        return $this->success(null, 'Pacienti boli uspesne odstraneni.');
    }

    /**
     * Restore one or more soft-deleted patients.
     *
     * @group Patients
     * @bodyParam ids array required Array of patient IDs to restore. Example: [1,2,3]
     * @response 200 {"success":true}
     */
    public function restoreMany(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            return $this->error('Neplatny payload.', 422);
        }

        Patient::withTrashed()->whereIn('id', $ids)->restore();

        return $this->success(null, 'Pacienti boli uspesne obnoveni.');
    }

    /**
     * Get patient's insurance company.
     *
     * @group Patients
     * @urlParam patient int required Patient ID. Example: 1
     * @response 200 {"data":{"id":1,"name":"Insurance Co"}}
     */
    public function insuranceCompany(Patient $patient): JsonResponse
    {
        $insuranceCompany = $patient->insuranceCompany;
        if (!$insuranceCompany) {
            return $this->notFound();
        }

        return $this->success($insuranceCompany, 'Poistovna bola uspesne nacitana.');
    }

    /**
     * Get patient's doctor.
     *
     * @group Patients
     * @urlParam patient int required Patient ID. Example: 1
     * @response 200 {"data":{"id":1,"first_name":"John","last_name":"Doe"}}
     */
    public function doctor(Patient $patient): JsonResponse
    {
        $doctor = $patient->doctor;
        if (!$doctor) {
            return $this->notFound();
        }

        return $this->success($doctor, 'Lekar bol uspesne nacitany.');
    }

    /**
     * List diagnoses for a patient.
     *
     * @group Patients
     * @urlParam patient int required Patient ID. Example: 1
     * @queryParam per_page int The number of items per page. Example: 15
     * @response 200 {"data":[{"id":1,"code":"A01","description":"Diagnosis"}],"meta":{"total":1}}
     */
    public function diagnoses(Request $request, Patient $patient): JsonResponse
    {
        $query = Diagnosis::query();
        $results = ApiQuery::apply($request, $query, searchable: ['code', 'description']);

        return $this->success(new BaseCollection($results), 'Diagnozy boli uspesne nacitane.');
    }

    /**
     * List procedures for a patient.
     *
     * @group Patients
     * @urlParam patient int required Patient ID. Example: 1
     * @queryParam per_page int The number of items per page. Example: 15
     * @response 200 {"data":[{"id":1,"code":"X123","description":"Procedure"}],"meta":{"total":1}}
     */
    public function procedures(Request $request, Patient $patient): JsonResponse
    {
        $query = Procedure::query();
        $results = ApiQuery::apply($request, $query, searchable: ['code', 'description']);

        return $this->success(new BaseCollection($results), 'Vykony boli uspesne nacitane.');
    }

    /**
     * List patient points (time series).
     *
     * @group Patients
     * @urlParam patient int required Patient ID. Example: 1
     * @queryParam date_from date optional Start date. Example: 2025-01-01
     * @queryParam date_to date optional End date. Example: 2025-01-31
     * @queryParam branch_id int optional Branch ID to filter. Example: 1
     * @queryParam per_page int The number of items per page. Example: 15
     * @response 200 {"data":[],"meta":{"total":0}}
     */
    public function points(PatientPointIndexRequest $request, Patient $patient): JsonResponse
    {
        $data = $request->validated();

        $query = PatientPoint::query()->where('patient_id', $patient->id);

        if ($request->filled('date_from')) {
            $query->whereDate('reference_date', '>=', $data['date_from']);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('reference_date', '<=', $data['date_to']);
        }

        $results = ApiQuery::apply(
            $request,
            $query,
            searchable: ['reference_date'],
            allowedFilters: ['reference_date', 'branch_id'],
            defaults: ['sort' => 'reference_date']
        );
        return $this->success(new BaseCollection($results), 'Body pacienta boli uspesne nacitane.');
    }

    /**
     * List patient documents.
     *
     * @group Patients
     * @urlParam patient int required Patient ID. Example: 1
     * @queryParam per_page int The number of items per page. Example: 15
     * @response 200 {"data":[],"meta":{"total":0}}
     */
    public function documents(Request $request, Patient $patient): JsonResponse
    {
        $query = Document::where('patient_id', $patient->id);

        $results = ApiQuery::apply($request, $query, ['name', 'type'], 'all', ['sort' => '-created_at']);

        return $this->success(new BaseCollection($results), 'Dokumenty pacienta boli uspesne nacitane.');
    }

}
