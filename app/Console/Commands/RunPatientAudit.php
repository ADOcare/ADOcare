<?php

namespace App\Console\Commands;

use App\Services\PatientAuditService;
use Illuminate\Console\Command;

/**
 * Run the patient data-quality audit.
 */
class RunPatientAudit extends Command
{
    protected $signature = 'patients:audit';

    protected $description = 'Audit patient data quality and reconcile patient audit issues.';

    /**
     * Run the audit and print its reconciliation summary.
     *
     * @param  PatientAuditService  $audit
     * @return int
     */
    public function handle(PatientAuditService $audit): int
    {
        $stats = $audit->run();

        $this->info('Patient audit completed.');
        $this->line("Patients checked: {$stats['patients_checked']}");
        $this->line("Issues found: {$stats['issues_found']}");
        $this->line("Opened: {$stats['opened']}");
        $this->line("Reopened: {$stats['reopened']}");
        $this->line("Still open: {$stats['still_open']}");
        $this->line("Resolved: {$stats['resolved']}");

        return self::SUCCESS;
    }
}
