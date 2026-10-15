<?php

namespace App\Services;

use App\Models\PatientProcedureDekurzTemplate;
use App\Models\Procedure;

class DekurzTextResolver
{
    /**
     * @return array{text: string, source: string}
     */
    public function resolve(?PatientProcedureDekurzTemplate $template, Procedure $procedure): array
    {
        if ($template && trim((string) $template->text) !== '') {
            return [
                'text' => (string) $template->text,
                'source' => 'patient_template',
            ];
        }

        if (trim((string) $procedure->default_dekurz_text) !== '') {
            return [
                'text' => (string) $procedure->default_dekurz_text,
                'source' => 'procedure_default',
            ];
        }

        return [
            'text' => '',
            'source' => 'empty',
        ];
    }
}