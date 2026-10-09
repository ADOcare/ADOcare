<?php

namespace App\Services\Transport;

use App\Models\Branch;
use App\Models\Car;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteService
{
    public function __construct(private StopGrouper $grouper, private RoutePlanner $planner)
    {
    }

    public function days(User $user, Branch $branch, string $from, string $to, ?int $carId = null): array
    {
        abort_unless($user->isInBranch((int) $branch->id), 403);
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        if ($end < $start || $start->format('Y-m') !== $end->format('Y-m')) {
            throw ValidationException::withMessages(['period' => ['Zvoľte obdobie v rámci jedného mesiaca.']]);
        }
        $car = $this->car($user, $branch, $carId);
        $branchData = $branch->only(['id', 'address', 'city', 'latitude', 'longitude', 'terrain_start_time', 'per_location_time']);
        foreach (['latitude', 'longitude'] as $coordinate) {
            if (!is_numeric($branchData[$coordinate] ?? null)) {
                throw ValidationException::withMessages(['branch' => ['Prevádzka nemá platné súradnice.']]);
            }
            $branchData[$coordinate] = (float) $branchData[$coordinate];
        }
        if (abs($branchData['latitude']) > 90 || abs($branchData['longitude']) > 180
            || !is_finite($branchData['latitude']) || !is_finite($branchData['longitude'])
            || trim((string) $branchData['address']) === '' || trim((string) $branchData['city']) === '') {
            throw ValidationException::withMessages(['branch' => ['Prevádzka nemá platnú adresu alebo súradnice.']]);
        }
        // Include every recorded patient visit, independent of insurance, regime and transport procedure.
        $rows = $this->sourceRows('patient_points', $user, $branch, $from, $to)
            ->unique(fn ($row) => $row->date . '|' . $row->patient_id)
            ->sortBy(fn ($row) => $row->date . '|' . str_pad((string) $row->patient_id, 12, '0', STR_PAD_LEFT));
        $days = [];
        foreach ($rows->groupBy('date') as $date => $visits) {
            try {
                $stops = $this->grouper->group($visits->map(fn ($row) => (array) $row)->all());
            } catch (\InvalidArgumentException $error) {
                throw ValidationException::withMessages(['transport' => [$date . ': ' . $error->getMessage()]]);
            }
            $fingerprint = hash('sha256', json_encode([config('transport.route_version', 1), $date, $branchData, $stops], JSON_THROW_ON_ERROR));
            $identity = ['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'service_date' => $date];
            $cached = DB::table('transport_routes')->where($identity)->where('fingerprint', $fingerprint)->first();
            if ($cached) {
                $days[] = json_decode($cached->payload, true, 512, JSON_THROW_ON_ERROR);
                continue;
            }
            $legs = $this->planner->plan($branchData, $stops, $date);
            foreach ($legs as &$leg) {
                if ($leg['is_return']) {
                    $leg['journey_id'] = null;
                    continue;
                }
                $journeyIdentity = $identity + ['stop_key' => $leg['stop_key']];
                DB::table('transport_journeys')->insertOrIgnore($journeyIdentity + ['created_at' => now(), 'updated_at' => now()]);
                $leg['journey_id'] = (int) DB::table('transport_journeys')->where($journeyIdentity)->value('id');
            }
            unset($leg);
            $day = ['date' => (string) $date, 'fingerprint' => $fingerprint, 'legs' => $legs];
            DB::table('transport_routes')->insertOrIgnore($identity + [
                'fingerprint' => $fingerprint,
                'payload' => json_encode($day, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // A concurrent request may have won; always return the persisted canonical result.
            $stored = DB::table('transport_routes')->where($identity)->where('fingerprint', $fingerprint)->value('payload');
            $days[] = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        }
        return $days;
    }

    public function car(User $user, Branch $branch, ?int $carId = null): Car
    {
        // Preserve the application's automatic assigned-car selection, with a stable order.
        $car = $user->cars()->where('company_id', $branch->company_id)
            ->when($carId !== null, fn ($query) => $query->whereKey($carId))->orderBy('id')->first();
        if (!$car) {
            throw ValidationException::withMessages(['car_id' => [
                $carId === null
                    ? 'Používateľ nemá priradené vozidlo v spoločnosti aktuálnej prevádzky. Doplňte priradenie vozidla v nastaveniach.'
                    : 'Vozidlo nie je priradené tomuto používateľovi v spoločnosti aktuálnej prevádzky.',
            ]]);
        }
        return $car;
    }

    private function sourceRows(string $table, User $user, Branch $branch, string $from, string $to)
    {
        return DB::table($table . ' as source')->join('patients as p', 'p.id', '=', 'source.patient_id')
            ->where('source.user_id', $user->id)->where('source.branch_id', $branch->id)
            ->whereBetween('source.date', [$from, $to])->where('source.quantity', '>', 0)
            ->select(['source.date', 'source.patient_id', 'p.address', 'p.city', 'p.latitude', 'p.longitude'])->get();
    }
}
