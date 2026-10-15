<?php

namespace App\Services\DocumentationAudit\Rules;

class CheckProposalForKosPlanRule extends RequiresSupportingDocumentRule
{
    public function code(): string
    {
        return 'DOC-004';
    }
}