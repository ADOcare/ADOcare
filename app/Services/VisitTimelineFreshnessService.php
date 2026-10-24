<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientPoint;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VisitTimelineFreshnessService
{
    /** @param array<string, mixed>|null $previous */
    public function invalidatePoint(PatientPoint $point, ?array $previous = null): void
    {
        $targets = collect([[
            'patient_id' => $point->patient_id,
            'date' => $point->date,
            'user_id' => $point->user_id,
            'branch_id' => $point->branch_id,
        ]]);

        if ($previous !== null) {
            $targets->push([
                'patient_id' => $previous['patient_id'] ?? null,
                'date' => $previous['date'] ?? null,
                'user_id' => $previous['user_id'] ?? null,
                'branch_id' => $previous['branch_id'] ?? null,
            ]);
        }

        $this->invalidateTargets($targets);
    }

    /** @param iterable<int, PatientPoint> $points */
    public function invalidatePoints(iterable $points): void
    {
        $this->invalidateTargets(collect($points)->map(fn (PatientPoint $point): array => [
            'patient_id' => $point->patient_id,
            'date' => $point->date,
            'user_id' => $point->user_id,
            'branch_id' => $point->branch_id,
        ]));
    }

    /** @param Collection<int, array<string, mixed>> $targets */
    private function invalidateTargets(Collection $targets): void
    {
        $targets = $targets->filter(
            fn (array $target): bool => (int) $target['patient_id'] > 0 && $target['date'] !== null,
        );
        $patients = Patient::withTrashed()
            ->whereKey($targets->pluck('patient_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $targets
            ->map(function (array $target) use ($patients): ?array {
                $patient = $patients->get((int) $target['patient_id']);
                $userId = (int) ($patient?->nurse_id ?: $target['user_id']);
                $branchId = (int) ($patient?->branch_id ?: $target['branch_id']);

                if ($userId <= 0 || $branchId <= 0) {
                    return null;
                }

                return [
                    'user_id' => $userId,
                    'branch_id' => $branchId,
                    'month' => Carbon::parse($target['date'])->startOfMonth()->toDateString(),
                ];
            })
            ->filter()
            ->unique(fn (array $target): string => implode('|', $target))
            ->each(function (array $target): void {
                DB::table('visit_calculations')
                    ->where('user_id', $target['user_id'])
                    ->where('branch_id', $target['branch_id'])
                    ->whereDate('month', $target['month'])
                    ->update([
                        'status' => 'pending',
                        'completed_at' => null,
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);
            });
    }
}
