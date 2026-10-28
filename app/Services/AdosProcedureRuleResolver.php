<?php

namespace App\Services;

/**
 * Resolves the scoped rules for an ADOS procedure.
 */
final class AdosProcedureRuleResolver
{
    /**
     * Get Proposal validation rules for a procedure.
     *
     * @return array<string, mixed>
     */
    public function proposal(string $procedureCode): array
    {
        return (array) config("ados_proposal_rules.procedures.{$procedureCode}.proposal", []);
    }

    /**
     * Get episode lifecycle metadata for a procedure.
     *
     * @return array<string, mixed>
     */
    public function episode(string $procedureCode): array
    {
        return (array) config("ados_proposal_rules.procedures.{$procedureCode}.episode", []);
    }

    /**
     * Get scoring rules for a procedure.
     *
     * @return array<string, mixed>
     */
    public function scoring(string $procedureCode): array
    {
        return (array) config("ados_proposal_rules.procedures.{$procedureCode}.scoring", []);
    }
}
