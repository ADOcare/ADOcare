<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Patient;
use App\Services\PatientNotificationService;
use Carbon\Carbon;

/**
 * Soft-delete patients whose latest patient_points entry is older than a configured cutoff.
 *
 * Usage: php artisan patients:soft-delete-inactive [--months=2] [--dry-run]
 */
class SoftDeleteInactivePatients extends Command
{
    protected $signature = 'patients:soft-delete-inactive {--months=2} {--dry-run}';

    protected $description = 'Soft-delete patients with no recent patient points (older than N months).';

    public function handle(PatientNotificationService $notificationService)
    {
        $months = max(1, (int) $this->option('months'));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = Carbon::now()->startOfDay()->subMonths($months);

        $this->info("Looking for patients whose last patient_points entry is before {$cutoff->toDateString()}...");

        $query = Patient::query()
            ->whereNull('death_date')
            ->where('created_at', '<', $cutoff)
            ->whereNotExists(function ($query) use ($cutoff) {
                $query->select(DB::raw(1))
                    ->from('patient_points')
                    ->whereColumn('patient_points.patient_id', 'patients.id')
                    ->where('patient_points.date', '>=', $cutoff->toDateString());
            });

        $count = (clone $query)->count();
        if ($count === 0) {
            $this->info('No inactive patients found.');
            return 0;
        }

        $this->info("Found {$count} patient(s) to consider for soft-deletion.");

        if ($dryRun) {
            // List a sample of patient IDs
            $this->line('Dry run mode — no deletions will be performed.');
            $this->line('Patient IDs: ' . (clone $query)->pluck('id')->implode(', '));
            return 0;
        }

        $deleted = 0;
        $query->with('branch')->chunkById(100, function ($patients) use (&$deleted, $notificationService, $months) {
            foreach ($patients as $patient) {
                if (! $patient instanceof Patient) {
                    continue;
                }

                try {
                    $notificationService->resolveIssue(
                        $patient,
                        "patient:{$patient->id}:insurance",
                    );

                    $notificationService->createEvent(
                        $patient,
                        "patient:{$patient->id}:inactive-deleted",
                        'patient_inactive_deleted',
                        'Pacient bol automaticky odstránený',
                        trim("{$patient->first_name} {$patient->last_name}") . " - bez výkonov za posledné {$months} mesiace",
                        ['patient_id' => $patient->id],
                    );

                    $patient->delete();
                    $deleted++;
                } catch (\Exception $e) {
                    // Log and continue
                    report($e);
                }
            }
        });

        $this->info("Soft-deleted {$deleted} patient(s).");
        return 0;
    }
}
