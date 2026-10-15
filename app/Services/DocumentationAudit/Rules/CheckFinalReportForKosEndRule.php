<?php

namespace App\Services\DocumentationAudit\Rules;

class CheckFinalReportForKosEndRule extends RequiresSupportingDocumentRule
{
    public function code(): string
    {
        return 'DOC-005';
    }
}