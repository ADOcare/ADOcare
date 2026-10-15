<?php

namespace App\Services\DocumentationAudit\Rules;

class CheckAgreementForAdmissionRule extends RequiresSupportingDocumentRule
{
    public function code(): string
    {
        return 'DOC-002';
    }
}