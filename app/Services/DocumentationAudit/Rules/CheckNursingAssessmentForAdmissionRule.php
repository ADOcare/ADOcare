<?php

namespace App\Services\DocumentationAudit\Rules;

class CheckNursingAssessmentForAdmissionRule extends RequiresSupportingDocumentRule
{
    public function code(): string
    {
        return 'DOC-003';
    }
}