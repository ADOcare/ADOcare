<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\User;
use App\Services\Transport\RouteService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CalculateVisitsTimeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries = 3;

    public function __construct(protected array $data)
    {
    }

    public function handle(RouteService $routes): void
    {
        $user = User::findOrFail((int) ($this->data['user_id'] ?? 0));
        $branch = Branch::findOrFail((int) ($this->data['branch_id'] ?? 0));
        $month = CarbonImmutable::parse($this->data['month'] ?? now(), 'Europe/Bratislava')->startOfMonth();
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $identity = ['user_id' => $user->id, 'branch_id' => $branch->id, 'month' => $from];
        try {
            // Always calculate the full route, before any insurance or claim selection.
            $days = $routes->days($user, $branch, $from, $to, isset($this->data['car_id']) ? (int) $this->data['car_id'] : null);
            if ($this->data['persist'] ?? true) {
                DB::transaction(function () use ($days, $user, $branch, $from, $to) {
                    DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                    DB::table('visits')->where('user_id', $user->id)->where('branch_id', $branch->id)
                        ->whereBetween('date', [$from, $to])->delete();
                    foreach ($days as $day) {
                        $admin = $branch->administrative_start_time
                            ? CarbonImmutable::parse($day['date'] . ' ' . $branch->administrative_start_time, 'Europe/Bratislava')
                            : null;
                        $rows = [];
                        foreach ($day['legs'] as $leg) {
                            $patients = $leg['is_return'] ? [null] : $leg['patient_ids'];
                            $serviceSeconds = $leg['is_return'] ? 0 : intdiv($leg['service_seconds'], count($patients));
                            foreach ($patients as $index => $patientId) {
                                $arrival = CarbonImmutable::parse($leg['arrival_time'], 'Europe/Bratislava')->addSeconds($index * $serviceSeconds);
                                $rows[] = [
                                    'date' => $day['date'], 'patient_id' => $patientId,
                                    'user_id' => $user->id, 'branch_id' => $branch->id,
                                    'terrain_time' => $arrival->format('Y-m-d H:i:s'),
                                    'administrative_time' => $patientId ? $admin?->format('Y-m-d H:i:s') : null,
                                    'time_on_location' => $serviceSeconds,
                                    'distance_to_location' => $index === 0 ? $leg['distance_m'] : 0,
                                    'time_to_location' => $index === 0 ? $leg['travel_seconds'] : 0,
                                    'created_at' => now(), 'updated_at' => now(),
                                ];
                                // Preserve a deterministic administrative estimate without random clock jitter.
                                if ($patientId && $admin) {
                                    $admin = $admin->addSeconds(180 + (crc32($day['date'] . '|' . $patientId . '|' . $user->id . '|' . $branch->id) % 181));
                                }
                            }
                        }
                        foreach (array_chunk($rows, 500) as $chunk) {
                            DB::table('visits')->insert($chunk);
                        }
                    }
                });
            }
            DB::table('visit_calculations')->updateOrInsert($identity, [
                'status' => 'completed', 'completed_at' => now(), 'error_message' => null, 'updated_at' => now(),
            ]);
        } catch (\Throwable $error) {
            DB::table('visit_calculations')->updateOrInsert($identity, [
                'status' => 'failed', 'completed_at' => null, 'error_message' => $error->getMessage(), 'updated_at' => now(),
            ]);
            throw $error;
        }
    }
}
