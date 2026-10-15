<?php

namespace App\Console\Commands;

use App\Services\DocumentationAudit\DocumentationAuditService;
use Illuminate\Console\Command;

class RunDocumentationAudit extends Command
{
    protected $signature = 'documentation:audit
        {--month= : Audit one eligible month in YYYY-MM format}
        {--from= : Audit eligible months from YYYY-MM onward}
        {--patient= : Limit the audit and resolution scope to one patient ID}
        {--company= : Limit the audit and resolution scope to one company ID}
        {--dry-run : Show changes without writing audit issues}';

    protected $description = 'Audit billed patient procedures against finalized supporting documentation.';

    public function handle(DocumentationAuditService $audit): int
    {
        if ($this->option('month') && $this->option('from')) {
            $this->error('Use either --month or --from, not both.');
            return self::INVALID;
        }
        foreach (['month', 'from'] as $option) {
            $value = $this->stringOption($option);
            if ($value !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
                $this->error("--{$option} must use YYYY-MM format.");
                return self::INVALID;
            }
        }

        $stats = $audit->run(
            month: $this->stringOption('month'),
            from: $this->stringOption('from'),
            patientId: $this->integerOption('patient'),
            companyId: $this->integerOption('company'),
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->newLine();
        $this->info('Documentation audit'.($stats['dry_run'] ? ' (dry run)' : ''));

        if ($stats['disabled']) {
            $this->warn('Audit is disabled by configuration.');
            return self::SUCCESS;
        }

        $this->line("Periods checked: {$stats['periods_checked']}");
        $this->line("Patients checked: {$stats['patients_checked']}");
        $this->line('Points checked: '.number_format($stats['points_checked']));
        $this->newLine();
        $this->line('Detected:');
        foreach ($stats['detected'] as $rule => $count) {
            $this->line("{$rule}: {$count}");
        }
        $this->newLine();
        $this->line("Opened: {$stats['opened']}");
        $this->line("Reopened: {$stats['reopened']}");
        $this->line("Still open: {$stats['still_open']}");
        $this->line("Resolved: {$stats['resolved']}");

        if (($stats['diagnostics']['legacy_not_structurally_verifiable'] ?? 0) > 0) {
            $this->warn('Legacy V1 Dekurz rows not structurally verifiable: '
                .$stats['diagnostics']['legacy_not_structurally_verifiable']);
        }

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function integerOption(string $name): ?int
    {
        $value = (int) $this->option($name);
        return $value > 0 ? $value : null;
    }
}