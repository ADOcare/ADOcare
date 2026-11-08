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
        $current = [
            'patient_id' => $point->patient_id,
            'date' => $point->date,
            'user_id' => $point->user_id,
            'branch_id' => $point->branch_id,
        ];

        if ($previous !== null && $this->sameTimelineTarget($current, $previous)) {
            return;
        }

        $targets = collect();

        if (! $this->hasAnotherPointForTarget($current, $point->exists ? (int) $point->id : null)) {
            $targets->push($current);
        }

        if ($previous !== null) {
            $previousTarget = [
                'patient_id' => $previous['patient_id'] ?? null,
                'date' => $previous['date'] ?? null,
                'user_id' => $previous['user_id'] ?? null,
                'branch_id' => $previous['branch_id'] ?? null,
            ];

            if (! $this->hasAnotherPointForTarget($previousTarget, (int) $point->id)) {
                $targets->push($previousTarget);
            }
        }

        $this->invalidateTargets($targets);
    }

    /** @param iterable<int, PatientPoint> $points */
    public function invalidatePoints(iterable $points): void
    {
        $targets = collect($points)
            ->map(fn (PatientPoint $point): array => [
                'patient_id' => $point->patient_id,
                'date' => $point->date,
                'user_id' => $point->user_id,
                'branch_id' => $point->branch_id,
            ])
            ->reject(fn (array $target): bool => $this->hasAnotherPointForTarget($target));

        $this->invalidateTargets($targets);
    }

    /** @param array<string, mixed> $target */
    private function hasAnotherPointForTarget(array $target, ?int $exceptId = null): bool
    {
        if ((int) ($target['patient_id'] ?? 0) <= 0 || empty($target['date'])) {
            return false;
        }

        return PatientPoint::query()
            ->where('patient_id', $target['patient_id'])
            ->whereDate('date', Carbon::parse($target['date'])->toDateString())
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     */
    private function sameTimelineTarget(array $current, array $previous): bool
    {
        return (int) ($current['patient_id'] ?? 0) === (int) ($previous['patient_id'] ?? 0)
            && Carbon::parse($current['date'])->toDateString() === Carbon::parse($previous['date'])->toDateString()
            && (int) ($current['user_id'] ?? 0) === (int) ($previous['user_id'] ?? 0)
            && (int) ($current['branch_id'] ?? 0) === (int) ($previous['branch_id'] ?? 0);
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
