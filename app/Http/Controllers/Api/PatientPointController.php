<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Filters\ApiQuery;
use App\Http\Requests\BulkUpdatePatientPointRequest;
use App\Http\Requests\DestroyManyPatientPointRequest;
use App\Http\Requests\StorePatientPointRequest;
use App\Http\Requests\UpdatePatientPointRequest;
use App\Http\Resources\BaseCollection;
use App\Jobs\ProcessBulkDeletionJob;
use App\Models\PatientPoint;
use App\Services\BulkDeletionResponder;
use App\Services\BulkDeletionService;
use App\Services\DocumentationAudit\ImmediateDocumentationAuditService;
use App\Services\PatientPointBulkEditService;
use App\Services\VisitTimelineFreshnessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PatientPointController extends Controller
{
    private BulkDeletionService $bulkDeletionService;

    private BulkDeletionResponder $bulkDeletionResponder;

    private ImmediateDocumentationAuditService $immediateAudit;

    private VisitTimelineFreshnessService $timelineFreshness;

    public function __construct(
        BulkDeletionService $bulkDeletionService,
        BulkDeletionResponder $bulkDeletionResponder,
        ImmediateDocumentationAuditService $immediateAudit,
        VisitTimelineFreshnessService $timelineFreshness,
    ) {
        $this->bulkDeletionService = $bulkDeletionService;
        $this->bulkDeletionResponder = $bulkDeletionResponder;
        $this->immediateAudit = $immediateAudit;
        $this->timelineFreshness = $timelineFreshness;
    }
    // No controller-level middleware: auth is enforced at the routes/v1 group.

    /**
     * Display a listing of patient points.
     *
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
        if (! isset($validated['procedure_id']) || ! $validated['procedure_id']) {
            $procedureId = DB::table('procedures')
                ->where('code', $validated['procedure_code'])
                ->value('id');

            if ($procedureId) {
                $validated['procedure_id'] = $procedureId;
            }
        }

        // Look up diagnosis_id from diagnosis_code if not provided
        if (! isset($validated['diagnosis_id']) || ! $validated['diagnosis_id']) {
            $diagnosisId = DB::table('diagnoses')
                ->where('code', $validated['diagnosis_code'])
                ->value('id');

            if ($diagnosisId) {
                $validated['diagnosis_id'] = $diagnosisId;
            }
        }

        $point = PatientPoint::create($validated);

        $this->timelineFreshness->invalidatePoint($point);
        $this->immediateAudit->patientPointChanged($point);

        // (Optional) make consistent with macros:
        return $this->success($point, 'Záznam bol vytvorený', Response::HTTP_CREATED);
    }

    /**
     * Display the specified patient point.
     *
     * GET /v1/patient-points/{patient_point}
     *
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
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdatePatientPointRequest $request, PatientPoint $patientPoint)
    {
        $this->authorize('update', $patientPoint);
        $validated = $request->validated();
        $previous = $patientPoint->only(['patient_id', 'date', 'user_id', 'branch_id']);

        $patientPoint->update($validated);

        $this->timelineFreshness->invalidatePoint($patientPoint, $previous);
        $this->immediateAudit->patientPointChanged($patientPoint, $previous);

        // (Optional) make consistent with macros:
        return $this->success($patientPoint, 'Záznam bol aktualizovaný');
    }

    /**
     * Remove the specified patient point.
     *
     * DELETE /v1/patient-points/{patient_point}
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy(PatientPoint $patientPoint)
    {
        $this->authorize('delete', $patientPoint);

        $patientPoint->delete();

        $this->timelineFreshness->invalidatePoint($patientPoint);
        $this->immediateAudit->patientPointChanged($patientPoint);

        // Return proper 204 No Content with no body
        return response()->noContent();
    }

    /**
     * Bulk delete patient points.
     *
     * DELETE /v1/patient-points (bulk)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyMany(DestroyManyPatientPointRequest $request)
    {
        $ids = $request->validated()['ids'] ?? [];
        $points = PatientPoint::query()->whereIn('id', $ids)->get();

        // The FormRequest already authorized each id; inform the service to skip
        // the duplicate pre-authorization step to avoid double-fetch.
        $result = $this->bulkDeletionService->handleBulkDelete($request->user(), PatientPoint::class, $ids, true);

        if (! empty($result['queued'])) {
            ProcessBulkDeletionJob::dispatch(PatientPoint::class, $ids, $request->user()?->id);
        } elseif (empty($result['error'])) {
            $this->timelineFreshness->invalidatePoints($points);
            $this->immediateAudit->patientPointsChanged($points);
        }

        // Delegate response rendering and logging to the responder. Use the
        // original user's id for logging/audit purposes. Message overrides can
        // be passed as the final argument if caller wants custom texts.
        return $this->bulkDeletionResponder->respond($result, PatientPoint::class, $ids, $request->user()?->id ?? null);
    }

    /**
     * Update shared values on compatible selected points without changing execution dates.
     *
     * @group Patient points
     *
     * @bodyParam ids int[] required IDs vybraných záznamov.
     * @bodyParam diagnosis_id int required ID diagnózy.
     * @bodyParam procedure_id int required ID výkonu.
     * @bodyParam quantity int required Počet.
     * @bodyParam reference_date string required Dátum odporučenia (YYYY-MM-DD).
     *
     * @response 200 {"message":"Vybrané záznamy boli upravené.","data":{"count":2}}
     */
    public function bulkUpdate(BulkUpdatePatientPointRequest $request, PatientPointBulkEditService $service)
    {
        $points = $service->update($request->user(), $request->validated());

        return $this->success(['count' => $points->count()], 'Vybrané záznamy boli upravené.');
    }

    /**
     * List matching dates within the bulk-edit baseline's calendar month.
     *
     * @group Patient points
     *
     * @urlParam patient_point int required ID východiskového záznamu.
     *
     * @queryParam page int Strana výsledkov.
     *
     * @response 200 {"message":"Zhodné záznamy boli načítané.","data":{"items":[],"meta":{"last_page":1}}}
     */
    public function matching(Request $request, PatientPoint $patientPoint, PatientPointBulkEditService $service)
    {
        $points = $service->matching($request->user(), $patientPoint)->paginate(100);

        return $this->success(new BaseCollection($points), 'Zhodné záznamy boli načítané.');
    }
}
