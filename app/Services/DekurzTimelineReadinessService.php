<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientPoint;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DekurzTimelineReadinessService
{
    /** @param Collection<int, PatientPoint> $points */
    public function assertReady(Patient $patient, User $actor, Collection $points): void
    {
        $requiredDates = $points
            ->pluck('date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->unique()
            ->values();

        if ($requiredDates->isEmpty()) {
            return;
        }

        $nurseId = (int) ($patient->nurse_id ?: $actor->id);
        $calculatedDates = DB::table('visits')
            ->where('patient_id', $patient->id)
            ->where('user_id', $nurseId)
            ->where('branch_id', $patient->branch_id)
            ->whereIn('date', $requiredDates->all())
            ->whereNotNull('terrain_time')
            ->whereNotNull('administrative_time')
            ->pluck('date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->unique();

        if ($requiredDates->diff($calculatedDates)->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'timeline' => 'Časy návštev pre tento mesiac ešte nie sú vypočítané. Najprv dokončite výpočet časovej osi v bodoch a potom skúste Dekurz znova.',
        ]);
    }
}
