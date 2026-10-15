<?php

namespace App\Services\DocumentationAudit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class DocumentationAuditPeriodSelector
{
    public function isEligible(string $period, ?CarbonImmutable $today = null): bool
    {
        $month = $this->parsePeriod($period);
        $today ??= CarbonImmutable::today();
        $start = CarbonImmutable::parse((string) config('documentation_audit.start_date'))->startOfDay();
        $eligibleAfter = $month->endOfMonth()->addDays((int) config('documentation_audit.grace_days', 5));

        return $month->endOfMonth()->greaterThanOrEqualTo($start)
            && $today->startOfDay()->greaterThan($eligibleAfter);
    }

    public function latestEligiblePeriod(?CarbonImmutable $today = null): ?string
    {
        $today ??= CarbonImmutable::today();
        $candidate = $today->startOfMonth()->subMonth();
        $start = CarbonImmutable::parse((string) config('documentation_audit.start_date'))->startOfMonth();

        while ($candidate->greaterThanOrEqualTo($start)) {
            $period = $candidate->format('Y-m');
            if ($this->isEligible($period, $today)) {
                return $period;
            }
            $candidate = $candidate->subMonth();
        }

        return null;
    }

    /** @return Collection<int, string> */
    public function range(string $from, string $through): Collection
    {
        $cursor = $this->parsePeriod($from);
        $end = $this->parsePeriod($through);
        $periods = collect();

        while ($cursor->lessThanOrEqualTo($end)) {
            $periods->push($cursor->format('Y-m'));
            $cursor = $cursor->addMonth();
        }

        return $periods;
    }

    private function parsePeriod(string $period): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new InvalidArgumentException("Invalid audit period: {$period}");
        }

        return CarbonImmutable::createFromFormat('!Y-m', $period);
    }
}