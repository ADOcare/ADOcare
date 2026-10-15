<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientProcedureDekurzTemplate;
use App\Models\Procedure;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PatientProcedureDekurzTemplateService
{
    public function __construct(private DekurzProcedureClassifier $classifier)
    {
    }

    public function upsert(Patient $patient, Procedure $procedure, string $text, User $actor): PatientProcedureDekurzTemplate
    {
        if (!$this->classifier->isDocumentable($procedure->code)) {
            throw ValidationException::withMessages([
                'procedure_id' => 'Pre tento výkon sa samostatný text Dekurzu nevytvára.',
            ]);
        }

        $template = PatientProcedureDekurzTemplate::query()->firstOrNew([
            'patient_id' => $patient->id,
            'procedure_id' => $procedure->id,
        ]);

        if (!$template->exists) {
            $template->created_by = $actor->id;
        }

        $template->fill([
            'text' => trim($text),
            'updated_by' => $actor->id,
        ])->save();

        return $template->fresh(['patient', 'procedure', 'creator', 'updater']);
    }

    public function delete(Patient $patient, Procedure $procedure): bool
    {
        return (bool) PatientProcedureDekurzTemplate::query()
            ->where('patient_id', $patient->id)
            ->where('procedure_id', $procedure->id)
            ->delete();
    }
}