<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Filters\ApiQuery;
use App\Http\Resources\BaseCollection;
use App\Http\Requests\DestroyManyPatientPointRequest;
use App\Services\BulkDeletionService;
use App\Services\BulkDeletionResponder;
use App\Jobs\ProcessBulkDeletionJob;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\StorePatientPointRequest;
use App\Http\Requests\UpdatePatientPointRequest;
use App\Models\PatientPoint;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PatientPointController extends Controller
{
    private BulkDeletionService $bulkDeletionService;
    private BulkDeletionResponder $bulkDeletionResponder;

    public function __construct(BulkDeletionService $bulkDeletionService, BulkDeletionResponder $bulkDeletionResponder)
    {
        $this->bulkDeletionService = $bulkDeletionService;
        $this->bulkDeletionResponder = $bulkDeletionResponder;
    }
    // No controller-level middleware: auth is enforced at the routes/v1 group.

    /**
     * Display a listing of patient points.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = PatientPoint::query();

        $request->validate([
            'period' => ['nullable', 'date_format:Y-m'],
        ]);

        if ($request->filled('period')) {
            $period = Carbon::createFromFormat(
                'Y-m',
                (string) $request->input('period'),
            );

            $query->whereBetween('date', [
                $period->copy()->startOfMonth()->toDateString(),
                $period->copy()->endOfMonth()->toDateString(),
            ]);
        }

        $results = ApiQuery::apply(
            $request,
            $query,
            searchable: ['patient_name', 'patient_personal_number', 'diagnosis_code', 'procedure_code'],
            allowedFilters: ['patient_id', 'user_id', 'branch_id', 'doctor_id', 'date'],
            defaults: ['sort' => '-date,id']
        );

        return $this->success(new BaseCollection($results), 'Záznamy pacienta boli načítané');
    }

    /**
     * Store a newly created patient point.
     *
     * POST /v1/patient-points
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StorePatientPointRequest $request)
    {
        // Authorization for create is handled in the FormRequest::authorize()
        $validated = $request->validated();
        if (empty($validated['user_id']) && $request->user()) {
            $validated['user_id'] = $request->user()->id;
        }

        // Look up procedure_id from procedure_code if not provided
        if (!isset($validated['procedure_id']) || !$validated['procedure_id']) {
            $procedureId = DB::table('procedures')
                ->where('code', $validated['procedure_code'])
                ->value('id');

            if ($procedureId) {
                $validated['procedure_id'] = $procedureId;
            }
        }

        // Look up diagnosis_id from diagnosis_code if not provided
        if (!isset($validated['diagnosis_id']) || !$validated['diagnosis_id']) {
            $diagnosisId = DB::table('diagnoses')
                ->where('code', $validated['diagnosis_code'])
                ->value('id');

            if ($diagnosisId) {
                $validated['diagnosis_id'] = $diagnosisId;
            }
        }

        $point = PatientPoint::create($validated);

        // (Optional) make consistent with macros:
        return $this->success($point, 'Záznam bol vytvorený', Response::HTTP_CREATED);
    }

    /**
     * Display the specified patient point.
     *
     * GET /v1/patient-points/{patient_point}
     *
     * @param  PatientPoint  $patientPoint
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(PatientPoint $patientPoint)
    {
        $this->authorize('view', $patientPoint);

        return $this->success($patientPoint, 'Záznam pacienta bol načítaný');
    }

    /**
     * Update the specified patient point.
     *
     * PUT/PATCH /v1/patient-points/{patient_point}
     *
     * @param  Request  $request
     * @param  PatientPoint  $patientPoint
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdatePatientPointRequest $request, PatientPoint $patientPoint)
    {
        $this->authorize('update', $patientPoint);
        $validated = $request->validated();

        $patientPoint->update($validated);

        // (Optional) make consistent with macros:
        return $this->success($patientPoint, 'Záznam bol aktualizovaný');
    }

    /**
     * Remove the specified patient point.
     *
     * DELETE /v1/patient-points/{patient_point}
     *
     * @param  PatientPoint  $patientPoint
     * @return \Illuminate\Http\Response
     */
    public function destroy(PatientPoint $patientPoint)
    {
        $this->authorize('delete', $patientPoint);

        $patientPoint->delete();

        // Return proper 204 No Content with no body
        return response()->noContent();
    }

    /**
     * Bulk delete patient points.
     *
     * DELETE /v1/patient-points (bulk)
     *
     * @param  DestroyManyPatientPointRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyMany(DestroyManyPatientPointRequest $request)
    {
        $ids = $request->validated()['ids'] ?? [];

        // The FormRequest already authorized each id; inform the service to skip
        // the duplicate pre-authorization step to avoid double-fetch.
        $result = $this->bulkDeletionService->handleBulkDelete($request->user(), PatientPoint::class, $ids, true);

        // Delegate response rendering and logging to the responder. Use the
        // original user's id for logging/audit purposes. Message overrides can
        // be passed as the final argument if caller wants custom texts.
        return $this->bulkDeletionResponder->respond($result, PatientPoint::class, $ids, $request->user()?->id ?? null);
    }
}
