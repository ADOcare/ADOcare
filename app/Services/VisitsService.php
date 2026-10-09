<?php
namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Encapsulates business logic related to visit calculations.
 *
 * Controllers should remain thin and delegate work to this service.
 */
class VisitsService
{
    /**
     * Queue a monthly timeline calculation and record the request in the database.
     *
     * @param array $data  Validated request data.
     *                     Keys: month, branch_id, user_id?, procedure_codes?, patients?, persist?
     * @return void
     */
    public function requestTimeline(array $data): void
    {
        // ensure user id is set, defaulting to currently authenticated user
        if (empty($data['user_id'])) {
            $data['user_id'] = Auth::id();
        }

        $month = Carbon::parse($data['month'])->startOfMonth()->toDateString();

        DB::table('visit_calculations')->updateOrInsert(
            [
                'user_id' => $data['user_id'],
                'branch_id' => $data['branch_id'],
                'month' => $month,
            ],
            [
                'status' => 'pending',
                'error_message' => null,
            ]
        );

        // dispatch job exactly as previous controller did
        \App\Jobs\CalculateVisitsTimeline::dispatch($data);
    }

    /**
     * Return the status row stored for a given calculation request.
     *
     * @param string $monthYmd
     * @param int $branchId
     * @param int|null $userId
     * @return array
     */
    public function checkCalculationStatus(string $monthYmd, int $branchId, ?int $userId = null): array
    {
        if (empty($userId)) {
            $userId = Auth::id();
        }

        $month = Carbon::parse($monthYmd)->startOfMonth()->toDateString();

        $calc = DB::table('visit_calculations')
            ->where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->where('month', $month)
            ->first();

        if (!$calc) {
            return ['status' => 'not_found'];
        }

        return [
            'status' => $calc->status,
            'completed_at' => $calc->completed_at,
            'error_message' => $calc->error_message,
        ];
    }

    /**
     * Compute day totals for visits, returning the aggregated fields used by the API.
     *
     * @param string $date   Y-m-d
     * @param int $branchId
     * @param int|null $userId
     * @param bool $includeOnLocation
     * @return array
     */
    public function getDayTotals(string $date, int $branchId, ?int $userId = null, bool $includeOnLocation = true): array
    {
        $userId = (int) ($userId ?? Auth::id());
        $branchId = (int) $branchId;
        $includeOnLocation = (bool) $includeOnLocation;

        $agg = DB::table('visits')
            ->where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->where('date', $date)
            ->selectRaw('
                COUNT(CASE WHEN patient_id IS NOT NULL THEN 1 END) as stops,
                COALESCE(SUM(time_to_location), 0) as travel_seconds,
                COALESCE(SUM(distance_to_location), 0) as distance_m,
                COALESCE(SUM(time_on_location), 0) as on_location_seconds,
                MIN(COALESCE(terrain_time, administrative_time)) as first_arrival,
                MAX(COALESCE(terrain_time, administrative_time)) as last_arrival
            ')
            ->first();

        $totalSeconds = (int) $agg->travel_seconds + ($includeOnLocation ? (int) $agg->on_location_seconds : 0);

        return [
            'date' => $date,
            'user_id' => $userId,
            'branch_id' => $branchId,
            'stops' => (int) $agg->stops,
            'travel_seconds' => (int) $agg->travel_seconds,
            'on_location_seconds' => (int) $agg->on_location_seconds,
            'total_seconds' => $totalSeconds,
            'distance_m' => (int) $agg->distance_m,
            'distance_km' => round(((int) $agg->distance_m) / 1000, 2),
            'first_arrival' => $agg->first_arrival,
            'last_arrival' => $agg->last_arrival,
        ];
    }

    /**
     * Compute month totals for visits, similar output to getDayTotals but aggregated over a month.
     *
     * @param string $monthAnyDate
     * @param int $branchId
     * @param int|null $userId
     * @param bool $includeOnLocation
     * @return array
     */
    public function getMonthTotals(string $monthAnyDate, int $branchId, ?int $userId = null, bool $includeOnLocation = true): array
    {
        $tz = 'Europe/Bratislava';
        $userId = (int) ($userId ?? Auth::id());
        $branchId = (int) $branchId;
        $includeOnLocation = (bool) $includeOnLocation;

        $month = Carbon::parse($monthAnyDate)->setTimezone($tz);
        $from = $month->copy()->startOfMonth()->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();

        $agg = DB::table('visits')
            ->where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COUNT(CASE WHEN patient_id IS NOT NULL THEN 1 END) as stops,
                COALESCE(SUM(time_to_location), 0) as travel_seconds,
                COALESCE(SUM(distance_to_location), 0) as distance_m,
                COALESCE(SUM(time_on_location), 0) as on_location_seconds,
                MIN(date) as from_date,
                MAX(date) as to_date
            ')
            ->first();

        $totalSeconds = (int) $agg->travel_seconds + ($includeOnLocation ? (int) $agg->on_location_seconds : 0);

        return [
            'month' => $month->format('Y-m'),
            'from' => $from,
            'to' => $to,
            'user_id' => $userId,
            'branch_id' => $branchId,
            'stops' => (int) $agg->stops,
            'travel_seconds' => (int) $agg->travel_seconds,
            'on_location_seconds' => (int) $agg->on_location_seconds,
            'total_seconds' => $totalSeconds,
            'distance_m' => (int) $agg->distance_m,
            'distance_km' => round(((int) $agg->distance_m) / 1000, 2),
        ];
    }
}
