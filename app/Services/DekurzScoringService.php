<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientPoint;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class DekurzScoringService
{
    public function __construct(private DekurzProcedureClassifier $classifier)
    {
    }

    /**
     * @return Collection<int, PatientPoint>
     */
    public function forMonth(Patient $patient, string $month): Collection
    {
        $period = Carbon::createFromFormat('!Y-m', $month);

        return PatientPoint::query()
            ->with('procedure')
            ->where('patient_id', $patient->id)
            ->where('branch_id', $patient->branch_id)
            ->whereBetween('date', [
                $period->copy()->startOfMonth()->toDateString(),
                $period->copy()->endOfMonth()->toDateString(),
            ])
            ->whereNotNull('procedure_id')
            ->orderBy('date')
            ->orderBy('procedure_code')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, PatientPoint>
     */
    public function documentableForMonth(Patient $patient, string $month): Collection
    {
        return $this->forMonth($patient, $month)
            ->filter(fn (PatientPoint $point): bool => $this->classifier->isDocumentable($point->procedure_code))
            ->values();
    }
}