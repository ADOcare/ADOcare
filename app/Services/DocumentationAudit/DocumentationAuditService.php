<?php

namespace App\Services\DocumentationAudit;

use App\Contracts\DocumentationAuditRule;
use App\Models\DocumentationAuditIssue;
use App\Models\Document;
use App\Models\Patient;
use App\Models\PatientPoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DocumentationAuditService
{
    public function __construct(
        private DocumentationAuditPeriodSelector $periods,
        private DocumentationAuditIssueRepository $issues,
        private DocumentationAuditNotificationService $notifications,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(
        ?string $month = null,
        ?string $from = null,
        ?int $patientId = null,
        ?int $companyId = null,
        bool $dryRun = false,
        ?CarbonImmutable $today = null,
        ?array $onlyRuleCodes = null,
        bool $allowOpenPeriod = false,
    ): array {
        $rules = $this->enabledRules($onlyRuleCodes);
        $ruleCodes = $rules->map(fn (DocumentationAuditRule $rule): string => $rule->code())->all();
        $stats = $this->emptyStats($rules, $dryRun);

        if (! config('documentation_audit.enabled', true)) {
            $stats['disabled'] = true;
            return $stats;
        }

        $selectedPeriods = $this->selectPeriods(
            $month,
            $from,
            $patientId,
            $companyId,
            $today,
            $allowOpenPeriod,
        );
        $stats['periods_checked'] = $selectedPeriods->count();

        foreach ($selectedPeriods as $period) {
            $findings = collect();
            $periodDiagnostics = [];
            $pointQuery = $this->pointQuery($period, $patientId, $companyId);
            $patientIds = (clone $pointQuery)->distinct()->pluck('patient_id')->filter()->map(fn ($id): int => (int) $id);
            $affectedPatientIds = DocumentationAuditIssue::query()
                ->where('period', $period)
                ->whereIn('rule_code', $ruleCodes)
                ->when($patientId, fn (Builder $query): Builder => $query->where('patient_id', $patientId))
                ->when($companyId, fn (Builder $query): Builder => $query->where('company_id', $companyId))
                ->pluck('patient_id')
                ->map(fn ($id): int => (int) $id)
                ->merge($patientIds)
                ->unique()
                ->values();
            $stats['patients_checked'] += $patientIds->count();

            foreach ($patientIds->chunk(100) as $patientChunk) {
                $ids = $patientChunk->all();
                $points = (clone $pointQuery)
                    ->whereIn('patient_id', $ids)
                    ->with(['procedure', 'branch'])
                    ->orderBy('id')
                    ->get();
                $stats['points_checked'] += $points->count();
                $patients = Patient::query()->active()->whereKey($ids)->with('branch')->get()->keyBy('id');
                $documents = Document::query()
                    ->whereIn('patient_id', $ids)
                    ->whereIn('type', ['dekurz', 'agreement', 'record', 'proposal', 'leave', 'scan'])
                    ->get()
                    ->groupBy('patient_id');

                foreach ($points->groupBy('patient_id') as $currentPatientId => $patientPoints) {
                    /** @var Collection<int, PatientPoint> $pointGroup */
                    $pointGroup = Collection::wrap($patientPoints)->values();
                    $patient = $patients->get($currentPatientId);
                    if (! $patient) {
                        continue;
                    }

                    $context = new DocumentationAuditContext(
                        $patient,
                        $period,
                        $pointGroup,
                        $documents->get($currentPatientId, collect()),
                    );

                    foreach ($rules as $rule) {
                        $ruleFindings = $rule->audit($context);
                        $findings->push(...$ruleFindings);
                        $stats['detected'][$rule->code()] += $ruleFindings->count();
                    }

                    foreach ($context->diagnostics() as $key => $count) {
                        $periodDiagnostics[$key] = ($periodDiagnostics[$key] ?? 0) + $count;
                    }
                }
            }

            $persistence = $this->issues->synchronize(
                $period,
                $findings,
                $ruleCodes,
                $dryRun,
                $patientId,
                $companyId,
            );
            foreach ($persistence as $key => $count) {
                $stats[$key] += $count;
            }
            if (! $dryRun) {
                $this->notifications->synchronize($period, $affectedPatientIds);
            }
            foreach ($periodDiagnostics as $key => $count) {
                $stats['diagnostics'][$key] = ($stats['diagnostics'][$key] ?? 0) + $count;
            }
        }

        return $stats;
    }

    /** @param array<int, string> $ruleCodes @return array<string, mixed> */
    public function runImmediate(string $period, int $patientId, array $ruleCodes): array
    {
        return $this->run(
            month: $period,
            patientId: $patientId,
            onlyRuleCodes: array_values(array_diff($ruleCodes, ['DOC-001'])),
            allowOpenPeriod: true,
        );
    }

    /** @return Collection<int, DocumentationAuditRule> */
    private function enabledRules(?array $onlyRuleCodes = null): Collection
    {
        return collect(config('documentation_audit.rules', []))
            ->filter(fn (array $settings): bool => (bool) ($settings['enabled'] ?? false))
            ->when(
                $onlyRuleCodes !== null,
                fn (Collection $rules): Collection => $rules->filter(
                    fn (array $settings, string $code): bool => in_array($code, $onlyRuleCodes, true),
                ),
            )
            ->map(fn (array $settings): DocumentationAuditRule => app($settings['class']))
            ->values();
    }

    /** @return Collection<int, string> */
    private function selectPeriods(
        ?string $month,
        ?string $from,
        ?int $patientId,
        ?int $companyId,
        ?CarbonImmutable $today,
        bool $allowOpenPeriod = false,
    ): Collection {
        $today ??= CarbonImmutable::today();
        $latest = $this->periods->latestEligiblePeriod($today);
        if (! $latest) {
            return collect();
        }

        if ($month !== null) {
            if ($allowOpenPeriod) {
                $configuredStart = CarbonImmutable::parse((string) config('documentation_audit.start_date'))->format('Y-m');
                $currentPeriod = $today->format('Y-m');

                return $month >= $configuredStart && $month <= $currentPeriod
                    ? collect([$month])
                    : collect();
            }

            return $this->periods->isEligible($month, $today) ? collect([$month]) : collect();
        }

        if ($from !== null) {
            $configuredStart = CarbonImmutable::parse((string) config('documentation_audit.start_date'))->format('Y-m');
            $effectiveFrom = max($from, $configuredStart);
            return $this->periods->range($effectiveFrom, $latest);
        }

        $openPeriods = DocumentationAuditIssue::query()
            ->where('status', DocumentationAuditIssue::STATUS_OPEN)
            ->when($patientId, fn (Builder $query): Builder => $query->where('patient_id', $patientId))
            ->when($companyId, fn (Builder $query): Builder => $query->where('company_id', $companyId))
            ->pluck('period')
            ->filter(fn (string $period): bool => $this->periods->isEligible($period, $today));

        return $openPeriods->push($latest)->unique()->sort()->values();
    }

    private function pointQuery(string $period, ?int $patientId, ?int $companyId): Builder
    {
        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $period)->startOfMonth();
        $configuredStart = CarbonImmutable::parse((string) config('documentation_audit.start_date'))->startOfDay();
        $start = $monthStart->max($configuredStart)->toDateString();
        $end = CarbonImmutable::createFromFormat('!Y-m', $period)->endOfMonth()->toDateString();

        return PatientPoint::query()
            ->whereBetween('date', [$start, $end])
            ->whereNotNull('patient_id')
            ->whereHas('patient', fn (Builder $patient): Builder => $patient->active())
            ->when($patientId, fn (Builder $query): Builder => $query->where('patient_id', $patientId))
            ->when($companyId, fn (Builder $query): Builder => $query->whereHas(
                'branch',
                fn (Builder $branch): Builder => $branch->where('company_id', $companyId),
            ));
    }

    /** @param Collection<int, DocumentationAuditRule> $rules @return array<string, mixed> */
    private function emptyStats(Collection $rules, bool $dryRun): array
    {
        return [
            'disabled' => false,
            'dry_run' => $dryRun,
            'periods_checked' => 0,
            'patients_checked' => 0,
            'points_checked' => 0,
            'detected' => $rules->mapWithKeys(fn (DocumentationAuditRule $rule): array => [$rule->code() => 0])->all(),
            'opened' => 0,
            'reopened' => 0,
            'still_open' => 0,
            'resolved' => 0,
            'diagnostics' => [],
        ];
    }
}