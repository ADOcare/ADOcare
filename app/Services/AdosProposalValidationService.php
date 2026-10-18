<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\Procedure;
use App\Support\ClinicalFrequency;
use Illuminate\Support\Collection;

class AdosProposalValidationService
{
    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    public function validate(Patient $patient, array $proposal): array
    {
        $rules = (array) config('ados_proposal_rules.procedures', []);
        $procedures = $this->normalizeProcedures($proposal['procedures'] ?? []);
        $result = [
            'version' => (string) config('ados_proposal_rules.version', '1.0'),
            'care_type' => (string) config('ados_proposal_rules.care_type', 'ADOS'),
            'errors' => [],
            'warnings' => [],
            'info' => [],
            'per_procedure' => [],
        ];
        $seenPairs = [];

        foreach ($procedures as $code => $procedure) {
            $rule = $rules[$code] ?? null;
            if (! is_array($rule)) {
                continue;
            }

            foreach ((array) ($rule['forbidden_with'] ?? []) as $forbiddenCode) {
                $forbiddenCode = (string) $forbiddenCode;
                if (! $procedures->has($forbiddenCode)) {
                    continue;
                }

                $pair = [$code, $forbiddenCode];
                sort($pair, SORT_NATURAL);
                $pairKey = implode(':', $pair);
                if (isset($seenPairs[$pairKey])) {
                    continue;
                }

                $seenPairs[$pairKey] = true;
                $this->addIssue($result, $this->issue(
                    'ADOS-'.implode('-', $pair).'-COMBINATION',
                    'forbidden_combination',
                    'error',
                    $pair,
                    "Výkony {$pair[0]} a {$pair[1]} nie je možné kombinovať.",
                ));
            }

            foreach ((array) ($rule['frequency'] ?? []) as $frequencyRule) {
                $this->evaluateFrequency($result, $code, $procedure['frequency'], (array) $frequencyRule);
            }

            $this->evaluateMobility($result, $code, $rule, (array) ($proposal['patient_mobility'] ?? []));
            $this->evaluateDuration($result, $code, $rule, (string) ($proposal['expected_duration'] ?? ''));
            $this->addEpisodeInfo($result, $code, $rule);

            $simpleTreatmentCount = $procedures
                ->keys()
                ->intersect(['3416', '3418', '3419'])
                ->count();
            if (($rule['isolated_care_warning'] ?? false) && $simpleTreatmentCount === 1) {
                $this->addIssue($result, $this->issue(
                    "ADOS-{$code}-ISOLATED-CARE",
                    'isolated_care',
                    'warning',
                    [$code],
                    'Tento výkon sa pri izolovanej jednorazovej starostlivosti nemusí akceptovať. Skontrolujte rozsah plánovanej ADOS starostlivosti.',
                ));
            }

            if (isset($rule['schedule'])) {
                $this->addIssue($result, $this->issue(
                    "ADOS-{$code}-SCHEDULE",
                    'schedule_context',
                    'info',
                    [$code],
                    'Výkon 3440 je určený na návštevu mimo riadneho pracovného času – počas pracovných dní mimo bežného pracovného času alebo počas soboty, nedele a sviatku.',
                ));
            }

            if (isset($rule['documentation_hint'])) {
                $this->addIssue($result, $this->issue(
                    "ADOS-{$code}-DOCUMENTATION",
                    'documentation_hint',
                    'info',
                    [$code],
                    (string) $rule['documentation_hint'],
                ));
            }
        }

        return $result;
    }

    /** @param array<int, mixed> $entries */
    private function normalizeProcedures(array $entries): Collection
    {
        $ids = collect($entries)
            ->pluck('procedure_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $codes = Procedure::query()->whereIn('id', $ids)->pluck('code', 'id');

        return collect($entries)
            ->filter(fn ($entry): bool => is_array($entry) && ! empty($entry['procedure_id']))
            ->map(function (array $entry) use ($codes): ?array {
                $code = $codes->get((int) $entry['procedure_id']);

                return $code ? [
                    'code' => (string) $code,
                    'frequency' => ClinicalFrequency::normalize((string) ($entry['frequency'] ?? '')),
                ] : null;
            })
            ->filter()
            ->keyBy('code');
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $rule */
    private function evaluateFrequency(array &$result, string $code, string $frequency, array $rule): void
    {
        $maximum = (int) ($rule['max'] ?? 0);
        $period = (string) ($rule['period'] ?? '');
        if ($maximum < 1 || $period === 'visit' || $frequency === '' || $frequency === 'once') {
            return;
        }

        if ((ClinicalFrequency::definition($frequency)['type'] ?? null) === 'as_needed') {
            $this->addIssue($result, $this->issue(
                "ADOS-{$code}-FREQUENCY-UNCERTAIN",
                'frequency_limit',
                'warning',
                [$code],
                "Pri výkone {$code} nie je možné frekvenciu podľa potreby presne porovnať s limitom vykazovania.",
            ));

            return;
        }

        $selected = ClinicalFrequency::occurrencesForPeriod($frequency, $period);

        if ($selected !== null && $selected > $maximum) {
            $periodLabel = $period === 'month' ? 'mesačne' : 'denne';
            $this->addIssue($result, $this->issue(
                "ADOS-{$code}-FREQUENCY",
                'frequency_limit',
                'error',
                [$code],
                "Výkon {$code} je možné vykazovať najviac {$maximum}× {$periodLabel}.",
            ));
        }
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $rule @param array<int, mixed> $mobility */
    private function evaluateMobility(array &$result, string $code, array $rule, array $mobility): void
    {
        $allowed = (array) ($rule['patient']['mobility'] ?? []);
        if ($allowed === []) {
            return;
        }

        $mapped = array_values(array_filter(array_map(
            fn ($value): ?string => match ((string) $value) {
                'H' => 'limited',
                'I' => 'immobile',
                default => null,
            },
            $mobility,
        )));

        if (array_intersect($allowed, $mapped) !== []) {
            return;
        }

        $message = $allowed === ['immobile']
            ? "Výkon {$code} je určený pre imobilného pacienta."
            : "Výkon {$code} je určený pre pacienta s obmedzenou pohyblivosťou alebo imobilného pacienta.";
        $this->addIssue($result, $this->issue(
            "ADOS-{$code}-MOBILITY",
            'mobility',
            'warning',
            [$code],
            $message,
        ));
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $rule */
    private function evaluateDuration(array &$result, string $code, array $rule, string $duration): void
    {
        $maximum = (int) ($rule['max_duration_months'] ?? 0);
        if ($maximum !== 3 || ! in_array($duration, ['six_months', 'over_six_months'], true)) {
            return;
        }

        $this->addIssue($result, $this->issue(
            "ADOS-{$code}-DURATION",
            'duration',
            'warning',
            [$code],
            'Pri tomto výkone je štandardná dĺžka starostlivosti najviac 3 mesiace.',
        ));
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $rule */
    private function addEpisodeInfo(array &$result, string $code, array $rule): void
    {
        if (! isset($rule['episode'])) {
            return;
        }

        $message = match ($code) {
            '3390' => 'Výkon 3390 patrí k začatiu alebo opätovnému začatiu epizódy ADOS.',
            '3394' => 'Výkon 3394 patrí k priebežnému hodnoteniu počas aktívnej epizódy ADOS a štandardne sa vykazuje najviac 1× mesačne.',
            '3395' => 'Výkon 3395 patrí k ukončeniu epizódy ADOS.',
            default => "Výkon {$code} súvisí so začatím, obnovením alebo významnou zmenou epizódy ADOS.",
        };
        $this->addIssue($result, $this->issue(
            "ADOS-{$code}-EPISODE",
            'episode_context',
            'info',
            [$code],
            $message,
        ));
    }

    /** @param array<int, string> $procedureCodes @return array<string, mixed> */
    private function issue(string $ruleCode, string $type, string $severity, array $procedureCodes, string $message): array
    {
        return [
            'rule_code' => $ruleCode,
            'type' => $type,
            'severity' => $severity,
            'procedure_codes' => $procedureCodes,
            'message' => $message,
            'source_reference' => null,
        ];
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $issue */
    private function addIssue(array &$result, array $issue): void
    {
        $bucket = match ($issue['severity']) {
            'error' => 'errors',
            'warning' => 'warnings',
            default => 'info',
        };
        $result[$bucket][] = $issue;

        foreach ($issue['procedure_codes'] as $code) {
            $result['per_procedure'][$code] ??= [];
            $result['per_procedure'][$code][] = $issue;
        }
    }
}
