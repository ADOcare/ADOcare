<?php

namespace App\Contracts;

use App\Services\DocumentationAudit\DocumentationAuditContext;
use Illuminate\Support\Collection;

interface DocumentationAuditRule
{
    public function code(): string;

    /** @return Collection<int, \App\Services\DocumentationAudit\DocumentationAuditFinding> */
    public function audit(DocumentationAuditContext $context): Collection;
}