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
    public function assertReady(Patient $patient, User $actor, Collection $points, string $month): void
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
        $calculation = DB::table('visit_calculations')
            ->where('user_id', $nurseId)
            ->where('branch_id', $patient->branch_id)
            ->whereDate('month', Carbon::parse($month)->startOfMonth()->toDateString())
            ->first();
        $latestPointUpdate = DB::table('patient_points')
            ->whereIn('id', $points->modelKeys())
            ->max('updated_at');

        if (
            ! $calculation
            || $calculation->status !== 'completed'
            || ! $calculation->completed_at
            || ($latestPointUpdate && Carbon::parse($calculation->completed_at)->lt(Carbon::parse($latestPointUpdate)))
        ) {
            throw ValidationException::withMessages([
                'timeline' => 'Časy návštev pre tento mesiac nie sú vypočítané alebo aktuálne. Je nutné pripraviť výkonové dávky.',
            ]);
        }

        $calculatedDates = DB::table('visits')
            ->where('patient_id', $patient->id)
            ->where('user_id', $nurseId)
            ->where('branch_id', $patient->branch_id)
            ->whereIn('date', $requiredDates->all())
            ->whereNotNull('terrain_time')
            ->whereNotNull('administrative_time')
            ->whereNotNull('time_on_location')
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
