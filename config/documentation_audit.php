<?php

use App\Services\DocumentationAudit\Rules\CheckAgreementForAdmissionRule;
use App\Services\DocumentationAudit\Rules\CheckFinalizedDekurzCoverageRule;
use App\Services\DocumentationAudit\Rules\CheckFinalReportForKosEndRule;
use App\Services\DocumentationAudit\Rules\CheckNursingAssessmentForAdmissionRule;
use App\Services\DocumentationAudit\Rules\CheckProposalForKosPlanRule;

return [
	'enabled' => env('DOCUMENTATION_AUDIT_ENABLED', true),
	'start_date' => env('DOCUMENTATION_AUDIT_START_DATE', '2026-08-01'),
	'grace_days' => (int) env('DOCUMENTATION_AUDIT_GRACE_DAYS', 5),

	'schedule' => [
		'enabled' => env('DOCUMENTATION_AUDIT_SCHEDULE_ENABLED', true),
		'time' => env('DOCUMENTATION_AUDIT_TIME', '03:00'),
	],

	'documentable_procedure_codes' => config('dekurz.documentable_procedure_codes', []),

	'rules' => [
		'DOC-001' => [
			'enabled' => env('DOCUMENTATION_AUDIT_DOC_001_ENABLED', true),
			'severity' => env('DOCUMENTATION_AUDIT_DOC_001_SEVERITY', 'error'),
			'start_date' => env('DOCUMENTATION_AUDIT_DOC_001_START_DATE', '2026-11-01'),
			'class' => CheckFinalizedDekurzCoverageRule::class,
		],
		'DOC-002' => [
			'enabled' => env('DOCUMENTATION_AUDIT_DOC_002_ENABLED', true),
			'severity' => env('DOCUMENTATION_AUDIT_DOC_002_SEVERITY', 'error'),
			'class' => CheckAgreementForAdmissionRule::class,
			'procedure_codes' => ['3390'],
			'document_type' => 'agreement',
		],
		'DOC-003' => [
			'enabled' => env('DOCUMENTATION_AUDIT_DOC_003_ENABLED', true),
			'severity' => env('DOCUMENTATION_AUDIT_DOC_003_SEVERITY', 'error'),
			'class' => CheckNursingAssessmentForAdmissionRule::class,
			'procedure_codes' => ['3390'],
			'document_type' => 'record',
			'match_period' => true,
			'date_tolerance_days' => 0,
		],
		'DOC-004' => [
			'enabled' => env('DOCUMENTATION_AUDIT_DOC_004_ENABLED', true),
			'severity' => env('DOCUMENTATION_AUDIT_DOC_004_SEVERITY', 'error'),
			'class' => CheckProposalForKosPlanRule::class,
			'procedure_codes' => ['3393'],
			'document_type' => 'proposal',
			'match_period' => true,
		],
		'DOC-005' => [
			'enabled' => env('DOCUMENTATION_AUDIT_DOC_005_ENABLED', true),
			'severity' => env('DOCUMENTATION_AUDIT_DOC_005_SEVERITY', 'error'),
			'class' => CheckFinalReportForKosEndRule::class,
			'procedure_codes' => ['3395'],
			'document_type' => 'leave',
			'match_period' => true,
		],
	],
];