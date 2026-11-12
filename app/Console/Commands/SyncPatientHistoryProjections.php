<?php

namespace App\Console\Commands;

use App\Services\PatientService;
use Illuminate\Console\Command;

class SyncPatientHistoryProjections extends Command
{
    protected $signature = 'patients:sync-history-projections';

    protected $description = "Synchronize today's effective patient-history values to compatibility columns.";

    public function handle(PatientService $patientService): int
    {
        $count = $patientService->syncCurrentProjections();
        $this->info("Synchronized current projections for {$count} patient records.");

        return self::SUCCESS;
    }
}
