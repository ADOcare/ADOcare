<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchDoctorController;
use App\Http\Controllers\Api\BranchPatientController;
use App\Http\Controllers\Api\CarController;
use App\Http\Controllers\Api\CarDocumentController;
use App\Http\Controllers\Api\CarServiceController;
use App\Http\Controllers\Api\KilometersExportController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PatientDeathCheckController;
use App\Http\Controllers\Api\InsuranceCompanyController;
use App\Http\Controllers\Api\DiagnosisController;
use App\Http\Controllers\Api\ProcedureController;
use App\Http\Controllers\Api\PatientPointController;
use App\Http\Controllers\Api\PointsExportController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\MyCompanyController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\ReportMonthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TextBlockController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\GeocodeController;
use \App\Http\Controllers\Api\MacroController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\DekurzController;
use App\Http\Controllers\Api\NurseDiagnosisController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ProposalDocumentController;
use App\Http\Controllers\Api\AgreementDocumentController;
use App\Http\Controllers\Api\CPDocumentController;
use App\Http\Controllers\Api\DZCDocumentController;
use App\Http\Controllers\Api\DekurzDocumentController;
use App\Http\Controllers\Api\LeaveDocumentController;
use App\Http\Controllers\Api\RecordDocumentController;
use App\Http\Controllers\Api\PointsExportController as PointsExportControllerAlias;
use App\Http\Controllers\Api\KilometersExportController as KilometersExportControllerAlias;
use App\Http\Controllers\Api\KilometersBatchDocumentController;
use App\Http\Controllers\Api\PointsBatchDocumentController;
use App\Http\Controllers\Api\BatchDocumentController;
use App\Http\Controllers\Api\VisitsController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\ManagerController;
use App\Http\Controllers\Api\TotalsController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\ScanSessionController;
use App\Http\Controllers\Api\ScanUploadController;
use App\Http\Controllers\Api\ScanFileController;
use App\Http\Controllers\Api\SubscriptionTierController;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\ForceJsonResponse;

Route::get('/v1/scans/{sessionId}/{filename}', [\App\Http\Controllers\Api\ScanFileController::class, 'image'])
    ->middleware(['api.auth', 'subscription.active'])
    ->where('filename', '.*');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['api.auth', 'subscription.active']);

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    // Public: creates the initial Manager + Company. Distinct from the superadmin-only
    // internal-staff `register` endpoint below.
    Route::post('register-company', [\App\Http\Controllers\Api\RegistrationController::class, 'store']);

    Route::middleware(['api.auth'])->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('role:superadmin');
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('profile', [AuthController::class, 'profile']);
    });
});


Route::get('/public/documents/{document}/data', [DocumentController::class, 'publicDocumentData'])
    ->name('documents.public.data')
    ->middleware('signed.url')->middleware('expires');

Route::get('/public/invoices/{invoice}/data', [InvoiceController::class, 'publicInvoiceData'])
    ->name('invoices.public.data')
    ->middleware('signed.url')->middleware('expires');

// The resolved application access state. Intentionally outside 'subscription.active' so a
// read-only or blocked Company can still load its own access context and renewal path.
Route::get('/v1/access', [\App\Http\Controllers\Api\AccessController::class, 'show'])
    ->middleware(['api.auth']);

// Billing routes intentionally bypass 'subscription.active' so a company whose
// subscription has lapsed can still view plans and start a new Checkout.
Route::prefix('v1/billing')->middleware(['api.auth'])->group(function () {
    Route::get('/plans', [\App\Http\Controllers\Api\BillingController::class, 'plans']);
    Route::get('/subscription', [\App\Http\Controllers\Api\BillingController::class, 'subscription']);
    Route::post('/checkout', [\App\Http\Controllers\Api\BillingController::class, 'checkout']);
    Route::post('/payment-method', [\App\Http\Controllers\Api\BillingController::class, 'paymentMethodPortal']);
    Route::post('/subscription/change', [\App\Http\Controllers\Api\BillingController::class, 'changeSubscription']);
    Route::post('/subscription/cancel', [\App\Http\Controllers\Api\BillingController::class, 'cancelSubscription']);
    Route::post('/subscription/resume', [\App\Http\Controllers\Api\BillingController::class, 'resumeSubscription']);
});

// Onboarding routes intentionally bypass 'subscription.active' - a Company mid-setup
// (or whose trial/subscription lapsed) must still be able to reach its own setup flow.
Route::prefix('v1/onboarding')->middleware(['api.auth', 'role:manager,superadmin'])->group(function () {
    Route::get('/status', [\App\Http\Controllers\Api\OnboardingController::class, 'status']);
    Route::post('/company', [\App\Http\Controllers\Api\OnboardingController::class, 'saveCompany']);
    Route::post('/billing/provision', [\App\Http\Controllers\Api\OnboardingController::class, 'provisionBilling']);
    Route::post('/billing/start-trial', [\App\Http\Controllers\Api\OnboardingController::class, 'startTrial']);
    Route::post('/complete', [\App\Http\Controllers\Api\OnboardingController::class, 'complete']);
});

Route::prefix('v1')->middleware(['api.auth', 'subscription.active'])->group(function () {

    Route::macro('apiResourceComplete', function ($name, $controller, ?string $middleware = null, ?string $readMiddleware = null) {
        if ($readMiddleware) {
            // Split read and write routes when read access should be broader than write access.
            Route::apiResource($name, $controller)
                ->only(['index', 'show'])
                ->middleware($readMiddleware);

            $resource = Route::apiResource($name, $controller)
                ->except(['index', 'show']);
        } else {
            // register the normal resource routes
            $resource = Route::apiResource($name, $controller);
        }

        if ($middleware) {
            $resource->middleware($middleware);
        }

        // register a collection-level DELETE route for bulk deletions
        $deleteRoute = Route::delete($name, [$controller, 'destroyMany']);
        if ($middleware) {
            $deleteRoute->middleware($middleware);
        }
    });

    Route::macro('documentRoutes', function ($name, $controller, $options = []) {
        Route::get("/{$name}", [$controller, 'index'])
            ->middleware('role:any');
        Route::post("/{$name}", [$controller, 'store'])
            ->middleware('role:any');
        Route::get("/{$name}/{document}/preview", [$controller, 'preview'])
            ->middleware(['role:any', 'can:view,document'])
            ->withoutMiddleware(ForceJsonResponse::class);
        Route::get("/{$name}/{document}/preview-url", [$controller, 'previewUrl'])
            ->middleware(['role:any', 'can:view,document']);

        Route::get("/{$name}/{document}/download", [$controller, 'download'])
            ->middleware(['role:any', 'can:view,document'])
            ->withoutMiddleware(ForceJsonResponse::class);

        Route::get("/{$name}/{document}", [$controller, 'show'])
            ->middleware(['role:any', 'can:view,document']);
    });

    Route::apiResourceComplete('cars', CarController::class);
    Route::delete('cars', [CarController::class, 'destroyMany']);

    // Car documents (scans, photos)
    Route::get('cars/{car}/documents', [CarDocumentController::class, 'index']);
    Route::post('cars/{car}/documents', [CarDocumentController::class, 'store']);
    Route::delete('cars/{car}/documents/{document}', [CarDocumentController::class, 'destroy']);
    Route::get('cars/{car}/documents/{document}/download', [CarDocumentController::class, 'download']);

    // Car services (maintenance tracking)
    Route::get('cars/{car}/services', [CarServiceController::class, 'index']);
    Route::post('cars/{car}/services', [CarServiceController::class, 'store']);
    Route::patch('cars/{car}/services/{service}', [CarServiceController::class, 'update']);
    Route::delete('cars/{car}/services/{service}', [CarServiceController::class, 'destroy']);
    Route::get('cars/services/due-this-month', [CarServiceController::class, 'dueThisMonth']);
    Route::get('my-cars/services/due-this-month', [CarServiceController::class, 'dueThisMonthForUser']);

    Route::apiResource('patients', PatientController::class)
        ->except(['index', 'store'])
        ->middleware('role:any');
    Route::delete('patients', [PatientController::class, 'destroyMany'])
        ->middleware('role:any');
    Route::post('patients/restore', [PatientController::class, 'restoreMany'])
        ->middleware('role:any');

    Route::get('patients/{patient}/death-check', [PatientDeathCheckController::class, 'show'])
        ->middleware(['role:any', 'can:view,patient']);

    Route::group(['prefix' => 'patients/{patient}', 'middleware' => ['role:any', 'can:view,patient']], function () {
        Route::get('insurance-company', [PatientController::class, 'insuranceCompany']);
        Route::get('doctor', [PatientController::class, 'doctor']);
        Route::get('diagnoses', [PatientController::class, 'diagnoses']);
        Route::get('procedures', [PatientController::class, 'procedures']);
        Route::get('patient-points', [PatientController::class, 'patientPoints']);
        Route::get('documents', [PatientController::class, 'documents']);
    });

    Route::apiResource('insurance-companies', InsuranceCompanyController::class)
        ->only(['index', 'show'])
        ->middleware('role:any');
    Route::apiResource('insurance-companies', InsuranceCompanyController::class)
        ->except(['index', 'show'])
        ->middleware('role:superadmin');
    Route::delete('insurance-companies', [InsuranceCompanyController::class, 'destroyMany'])
        ->middleware('role:superadmin');

    Route::apiResourceComplete('branches', BranchController::class, 'role:manager,superadmin');
    Route::delete('branches/delete-many', [BranchController::class, 'destroyMany'])
        ->middleware('role:manager,superadmin');
    Route::get('branches/{branch}/patients', [BranchPatientController::class, 'index'])
        ->middleware('role:any');
    Route::post('branches/{branch}/patients', [BranchPatientController::class, 'store'])
        ->middleware('role:any');
    Route::get('branches/{branch}/nurses', [BranchController::class, 'nurses'])
        ->middleware('role:any');
    Route::get('/branches/{branch}/favourite-doctors', [BranchDoctorController::class, 'doctors'])
        ->middleware('role:any');
    Route::post('/branches/{branch}/favourite-doctors/{doctor}', [BranchDoctorController::class, 'attach'])
        ->middleware('role:any');
    Route::delete('/branches/{branch}/favourite-doctors/{doctor}', [BranchDoctorController::class, 'detach'])
        ->middleware('role:any');

    Route::get('/my-company', [MyCompanyController::class, 'show']);
    Route::patch('/my-company', [MyCompanyController::class, 'update'])
        ->middleware('role:manager,superadmin');
    Route::delete('/my-company', [MyCompanyController::class, 'destroy'])
        ->middleware('role:manager,superadmin');
    Route::get('/my-company/branches', [MyCompanyController::class, 'branches']);
    Route::get('/my-company/cars', [MyCompanyController::class, 'cars']);
    Route::get('/my-company/users', [MyCompanyController::class, 'users']);
    Route::get('/my-company/doctors', [MyCompanyController::class, 'doctors']);
    Route::get('/my-company/subscription-details', [MyCompanyController::class, 'subscriptionDetails'])
        ->middleware('role:manager,superadmin');
    Route::get('/my-company/subscription-payments', [MyCompanyController::class, 'subscriptionPayments'])
        ->middleware('role:manager,superadmin');

    Route::group(['prefix' => 'manager', 'middleware' => 'role:manager,superadmin'], function () {
        Route::get('/user-statistics', [ManagerController::class, 'userStatistics']);
        Route::get('/doctor-statistics', [ManagerController::class, 'doctorStatistics']);
        Route::get('/user-totals', [ManagerController::class, 'userTotals']);
        Route::get('/branch-statistics', [ManagerController::class, 'branchStatistics']);
        Route::get('/branch-totals', [ManagerController::class, 'branchTotals']);
        Route::get('/user-totals-aggregated', [ManagerController::class, 'userTotalsAggregated']);
        Route::get('/financial-statistics', [ManagerController::class, 'financialStatistics']);
    });

    // superadmin-specific endpoints
    Route::group(['prefix' => 'superadmin', 'middleware' => 'role:superadmin'], function () {
        Route::get('/statistics', [\App\Http\Controllers\Api\SuperadminController::class, 'statistics']);
    });

    Route::apiResourceComplete('totals', TotalsController::class, 'role:manager,superadmin');

    Route::apiResource('doctors', DoctorController::class)
        ->only(['index', 'show'])
        ->middleware('role:any');
    Route::apiResource('doctors', DoctorController::class)
        ->except(['index', 'show'])
        ->middleware('role:superadmin');
    Route::delete('doctors', [DoctorController::class, 'destroyMany'])
        ->middleware('role:superadmin');

    Route::apiResource('diagnoses', DiagnosisController::class)
        ->only(['index', 'show'])
        ->middleware('role:any');
    Route::apiResource('diagnoses', DiagnosisController::class)
        ->except(['index', 'show'])
        ->middleware('role:superadmin');
    Route::delete('diagnoses', [DiagnosisController::class, 'destroyMany'])
        ->middleware('role:superadmin');
    Route::apiResourceComplete('nurse-diagnoses', NurseDiagnosisController::class, 'role:manager,superadmin', 'role:any');
    Route::apiResourceComplete('macros', MacroController::class);
    Route::get('/invoices/{invoice}/file', [InvoiceController::class, 'file'])
        ->middleware('role:manager,superadmin');
    Route::get('/invoices/{invoice}/preview', [InvoiceController::class, 'preview'])
        ->middleware('role:manager,superadmin')
        ->withoutMiddleware(ForceJsonResponse::class);
    Route::get('/invoices/{invoice}/preview-url', [InvoiceController::class, 'previewUrl'])
        ->middleware('role:manager,superadmin');
    Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download'])
        ->middleware('role:manager,superadmin')
        ->withoutMiddleware(ForceJsonResponse::class);
    Route::apiResourceComplete('invoices', InvoiceController::class, 'role:manager,superadmin');
    Route::apiResourceComplete('plans', PlanController::class);
    Route::delete('/plans', [PlanController::class, 'destroyMany']);
    Route::apiResource('procedures', ProcedureController::class)
        ->only(['index', 'show'])
        ->middleware('role:any');
    Route::apiResource('procedures', ProcedureController::class)
        ->only(['update'])
        ->middleware('role:manager,superadmin');
    Route::apiResource('procedures', ProcedureController::class)
        ->only(['store', 'destroy'])
        ->middleware('role:superadmin');
    Route::delete('procedures', [ProcedureController::class, 'destroyMany'])
        ->middleware('role:superadmin');
    Route::apiResourceComplete('patient-points', PatientPointController::class, 'role:any');
    Route::apiResourceComplete('report-months', ReportMonthController::class, 'role:any');
    Route::group(['prefix' => 'roles', 'middleware' => 'role:any'], function () {
        Route::get('/branch', [RoleController::class, 'branchRoles']);
        Route::get('/company', [RoleController::class, 'companyRoles']);
        Route::get('/system', [RoleController::class, 'systemRoles']);
        Route::get('/all', [RoleController::class, 'allRoles']);
    });
    Route::apiResourceComplete('roles', RoleController::class, 'role:superadmin');

    Route::apiResourceComplete('text-blocks', TextBlockController::class);
    Route::delete('/users', [UserController::class, 'destroyMany'])
        ->middleware('role:manager,superadmin');
    Route::delete('/users/{user}/branches/{branch}', [UserController::class, 'deleteBranchAssignment'])
        ->middleware('role:manager,superadmin');
    Route::get('/users/{user}/signature', [UserController::class, 'signature'])
        ->middleware('role:manager,superadmin');
    Route::post('/users/{user}/signature', [UserController::class, 'uploadSignature'])
        ->middleware('role:manager,superadmin');
    Route::delete('/users/{user}/signature', [UserController::class, 'deleteSignature'])
        ->middleware('role:manager,superadmin');
    Route::apiResourceComplete('users', UserController::class, 'role:manager,superadmin');
    Route::get('companies/subscriptions', [CompanyController::class, 'subscriptions'])
        ->middleware('role:superadmin');
    Route::get('companies/{company}/subscription-details', [CompanyController::class, 'subscriptionDetails'])
        ->middleware('role:superadmin');
    Route::apiResourceComplete('companies', CompanyController::class, 'role:superadmin');
    // Subscription tiers are legacy/historical reference data now - StudioKristian owns plan management.
    Route::apiResource('subscription-tiers', SubscriptionTierController::class)
        ->only(['index', 'show'])
        ->middleware('role:superadmin');
    Route::get('companies/{company}/stats', [CompanyController::class, 'stats'])
        ->middleware('role:manager,superadmin');
    Route::get('companies/{company}/users', [CompanyController::class, 'users'])
        ->middleware('role:manager,superadmin');
    Route::get('companies/{company}/branches', [CompanyController::class, 'branches'])
        ->middleware('role:manager,superadmin');
    Route::get('companies/{company}/stamp', [CompanyController::class, 'stamp'])
        ->middleware('role:any');
    Route::delete('companies/{company}/stamp', [CompanyController::class, 'deleteStamp'])
        ->middleware('role:manager,superadmin');



    // Points/kilometers batch endpoints use POST for their filter payload but only ever read
    // and export existing data - they must stay available while an account is read-only.
    Route::post('/batches/points/preview', [PointsExportController::class, 'preview'])
        ->middleware('role:any')
        ->withoutMiddleware('subscription.active')->middleware('subscription.active:read');
    Route::post('/batches/points/download', [PointsExportController::class, 'download'])
        ->middleware('role:any')
        ->withoutMiddleware('subscription.active')->middleware('subscription.active:read');
    Route::post('/batches/points/statement-pdf', [PointsExportController::class, 'statementPdf'])
        ->middleware('role:any')
        ->withoutMiddleware('subscription.active')->middleware('subscription.active:read');

    Route::post('/batches/kilometers/preview', [KilometersExportController::class, 'preview'])
        ->middleware('role:any')
        ->withoutMiddleware('subscription.active')->middleware('subscription.active:read');
    Route::post('/batches/kilometers/download', [KilometersExportController::class, 'download'])
        ->middleware('role:any')
        ->withoutMiddleware('subscription.active')->middleware('subscription.active:read');
    Route::post('/batches/kilometers/statement-pdf', [KilometersExportController::class, 'statementPdf'])
        ->middleware('role:any')
        ->withoutMiddleware('subscription.active')->middleware('subscription.active:read');

    Route::get('/geocode/autocomplete', [GeocodeController::class, 'autocomplete']);
    Route::get('/geocode/details', [GeocodeController::class, 'details']);
    Route::get('/geocode/reverse', [GeocodeController::class, 'reverse']);

    Route::get('/companies/{company}/patients', [CompanyController::class, 'patients'])
        ->middleware('role:manager,superadmin');

    Route::post('/branches/{branch}/doctors/{doctor}', [BranchDoctorController::class, 'attach'])
        ->middleware('role:manager,superadmin');
    Route::delete('/branches/{branch}/doctors/{doctor}', [BranchDoctorController::class, 'detach'])
        ->middleware('role:manager,superadmin');

    Route::documentRoutes('proposals', ProposalDocumentController::class);

    // Patient-specific proposal routes
    Route::get('/patients/{patient}/proposals', [ProposalDocumentController::class, 'getByPatient'])
        ->middleware(['role:any', 'can:view,patient']);
    Route::get('/patients/{patient}/proposals/latest', [ProposalDocumentController::class, 'latestByPatient'])
        ->middleware(['role:any', 'can:view,patient']);
    Route::get('/patients/{patient}/proposals/ocr-prefill/availability', [ProposalDocumentController::class, 'ocrPrefillAvailability'])
        ->middleware(['role:any', 'can:view,patient']);
    Route::post('/patients/{patient}/proposals/ocr-prefill', [ProposalDocumentController::class, 'prefillFromLatestScan'])
        ->middleware(['role:any', 'can:view,patient']);

    Route::documentRoutes('agreements', AgreementDocumentController::class);

    Route::documentRoutes('cps', CPDocumentController::class);
    Route::documentRoutes('dzcs', DZCDocumentController::class);

    Route::get('/dzcs/{document}/csv', [DZCDocumentController::class, 'exportCsv'])
        ->middleware(['role:any', 'can:view,document']);


    // Special dekurz endpoints
    Route::get('/dekurz/available-dates', [DekurzDocumentController::class, 'availableDates'])
        ->middleware('role:any');
    Route::get('/dekurz/last', [DekurzDocumentController::class, 'last'])
        ->middleware('role:any');
    Route::documentRoutes('dekurz', DekurzDocumentController::class);
    Route::post('/patients/{patient}/dekurz/ai-prefill', [DekurzDocumentController::class, 'prefillFromLatestProposal'])
        ->middleware(['role:any', 'can:view,patient']);
    Route::post('/patients/{patient}/dekurz/ai-improve-text', [DekurzDocumentController::class, 'improveText'])
        ->middleware(['role:any', 'can:view,patient']);

    Route::documentRoutes('leave-documents', LeaveDocumentController::class);
    Route::get('/patients/{patient}/leave/latest', [LeaveDocumentController::class, 'latestByPatient'])
        ->middleware(['role:any', 'can:view,patient']);

    Route::documentRoutes('records', RecordDocumentController::class);
    Route::get('/patients/{patient}/records/latest', [RecordDocumentController::class, 'latestByPatient'])
        ->middleware(['role:any', 'can:view,patient']);

    Route::documentRoutes('kilometers-batches', KilometersBatchDocumentController::class);
    Route::get('/patients/{patient}/kilometers-batches/latest', [KilometersBatchDocumentController::class, 'latestByPatient'])
        ->middleware(['role:any', 'can:view,patient']);

    Route::documentRoutes('points-batches', PointsBatchDocumentController::class);

    Route::get('/batch-documents/company', [BatchDocumentController::class, 'indexByCompany'])
        ->middleware('role:manager,superadmin');
    Route::get('/batch-documents/company/aggregated-branch', [BatchDocumentController::class, 'aggregatedByBranch'])
        ->middleware('role:manager,superadmin');
    Route::get('/batch-documents/company/aggregated-user', [BatchDocumentController::class, 'aggregatedByUser'])
        ->middleware('role:manager,superadmin');

    Route::post('/documents/generate-pdf', [DocumentController::class, 'generatePdf']);
    // Existence check only - reads, never writes.
    Route::post('/documents/check-exists', [DocumentController::class, 'checkExists'])
        ->withoutMiddleware('subscription.active')->middleware('subscription.active:read');
    Route::post('/documents/email', [DocumentController::class, 'emailDocuments']);
    Route::post('/documents/travel/company/create', [DocumentController::class, 'createCompanyTravelDocument'])
        ->middleware('role:manager,superadmin');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])
        ->middleware(['role:any', 'can:delete,document']);
    Route::delete('/documents', [DocumentController::class, 'destroyMany']);
    Route::get('/documents/travel/company', [DocumentController::class, 'indexTravelDocumentsForCompany']);
    Route::get('/documents/travel', [DocumentController::class, 'indexTravelDocuments']);

    // Timeline is a computed read - POST is only used to carry the filter payload.
    Route::post('/visits/timeline', [VisitsController::class, 'monthTimeline'])
        ->middleware('role:any')
        ->withoutMiddleware('subscription.active')->middleware('subscription.active:read');
    Route::get('/visits/timeline/status', [VisitsController::class, 'checkCalculationStatus'])
        ->middleware('role:any');
    Route::get('/visits', [VisitsController::class, 'index'])
        ->middleware('role:any');
    Route::get('visits/patient-time', [VisitsController::class, 'patientTimeForDay'])
        ->middleware('role:any');
    Route::get('visits/day-totals', [VisitsController::class, 'dayTotals'])
        ->middleware('role:any');
    Route::get('visits/month-totals', [VisitsController::class, 'monthTotals'])
        ->middleware('role:any');

    Route::get('/cities/suggest', [CityController::class, 'suggest']);
    Route::get('/cities/by-zip', [CityController::class, 'byZip']);

    Route::get('/countries', [CountryController::class, 'index']);

    Route::post('/scan-sessions', [ScanSessionController::class, 'store']);
    Route::get('/scan-sessions/{sessionId}', [ScanSessionController::class, 'show']);

    Route::documentRoutes('scan', ScanFileController::class);
    Route::patch('/scan/{document}/text', [ScanFileController::class, 'updateText'])
        ->middleware(['role:any', 'can:update,document']);

});

// Public scan upload routes (no auth required - guest access with token)
Route::post('/scan/upload', [ScanUploadController::class, 'uploadImage']);
Route::post('/scan/finalize', [ScanUploadController::class, 'finalize']);
Route::post('/scan/info', [ScanUploadController::class, 'getSessionInfo']);
