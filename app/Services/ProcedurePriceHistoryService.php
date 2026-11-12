<?php

namespace App\Services;

use App\Models\InsuranceCompany;
use App\Models\Procedure;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class ProcedurePriceHistoryService
{
    public function applyPrices(
        int $procedureId,
        array $prices,
        int $companyId,
        ?int $changedBy,
        bool $isCreation = false,
    ): void {
        $recordedAt = now();

        foreach ($prices as $price) {
            $effectiveDate = $price['effective_date'] ?? ($isCreation ? now()->toDateString() : null);

            if ($effectiveDate === null) {
                if ($this->currentPriceEquals(
                    $procedureId,
                    (int) $price['insurance_company_id'],
                    $companyId,
                    (float) $price['price'],
                )) {
                    continue;
                }

                throw ValidationException::withMessages([
                    'prices' => 'Každá zmenená cena musí mať dátum platnosti.',
                ]);
            }

            $this->applyPrice(
                $procedureId,
                (int) $price['insurance_company_id'],
                $companyId,
                (float) $price['price'],
                CarbonImmutable::parse($effectiveDate)->startOfDay(),
                $changedBy,
                $recordedAt,
            );
        }
    }

    public function history(Procedure $procedure, int $companyId): Collection
    {
        $periods = DB::table('procedure_company_prices as pcp')
            ->join('insurance_companies as ic', 'ic.id', '=', 'pcp.insurance_company_id')
            ->leftJoin('users as changed_by_user', 'changed_by_user.id', '=', 'pcp.changed_by')
            ->where('pcp.procedure_id', $procedure->id)
            ->where('pcp.company_id', $companyId)
            ->get([
                'pcp.id',
                'pcp.insurance_company_id',
                'ic.name as insurance_company_name',
                'ic.code as insurance_company_code',
                'pcp.price',
                'pcp.valid_from',
                'pcp.valid_to',
                'pcp.created_at',
                'pcp.updated_at',
                'pcp.changed_by',
                'changed_by_user.title as changed_by_title',
                'changed_by_user.first_name as changed_by_name',
                'changed_by_user.last_name as changed_by_surname',
            ]);

        return $periods
            ->groupBy(function (object $period): string {
                if ($period->valid_from === null) {
                    return 'baseline';
                }

                return implode('|', [
                    $period->valid_from,
                    $period->updated_at,
                    $period->changed_by ?? 'system',
                ]);
            })
            ->map(function (Collection $group, string $key): array {
                $first = $group->first();
                $isBaseline = $key === 'baseline';

                return [
                    'key' => $key,
                    'effective_date' => $isBaseline ? null : $first->valid_from,
                    'recorded_at' => $isBaseline
                        ? $group->min('created_at')
                        : $group->max('updated_at'),
                    'changed_by' => $first->changed_by,
                    'changed_by_title' => $first->changed_by_title,
                    'changed_by_name' => $first->changed_by_name,
                    'changed_by_surname' => $first->changed_by_surname,
                    'items' => $group
                        ->sortBy('insurance_company_name')
                        ->map(fn (object $period): array => [
                            'id' => $period->id,
                            'insurance_company_id' => $period->insurance_company_id,
                            'insurance_company_name' => $period->insurance_company_name,
                            'insurance_company_code' => $period->insurance_company_code,
                            'price' => $period->price,
                            'valid_from' => $period->valid_from,
                            'valid_to' => $period->valid_to,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->sort(function (array $left, array $right): int {
                $leftDate = $left['effective_date'] ?? '';
                $rightDate = $right['effective_date'] ?? '';

                return $rightDate <=> $leftDate
                    ?: $right['recorded_at'] <=> $left['recorded_at'];
            })
            ->values();
    }

    public function state(Procedure $procedure, int $companyId, string $date): array
    {
        $prices = $this->pricesForDate($procedure->id, $companyId, CarbonImmutable::parse($date));
        $companies = InsuranceCompany::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return [
            'date' => CarbonImmutable::parse($date)->toDateString(),
            'prices' => $companies->map(function (InsuranceCompany $insuranceCompany) use ($prices): array {
                $period = $prices->get($insuranceCompany->id);

                return [
                    'insurance_company_id' => $insuranceCompany->id,
                    'insurance_company_name' => $insuranceCompany->name,
                    'insurance_company_code' => $insuranceCompany->code,
                    'price' => $period?->price,
                    'valid_from' => $period?->valid_from,
                    'valid_to' => $period?->valid_to,
                ];
            })->values()->all(),
        ];
    }

    private function applyPrice(
        int $procedureId,
        int $insuranceCompanyId,
        int $companyId,
        float $price,
        CarbonImmutable $effectiveDate,
        ?int $changedBy,
        CarbonInterface $recordedAt,
    ): void {
        $periods = DB::table('procedure_company_prices')
            ->where('procedure_id', $procedureId)
            ->where('insurance_company_id', $insuranceCompanyId)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->get();

        $effectivePeriod = $this->periodForDate($periods, $effectiveDate);
        if ($effectivePeriod !== null
            && $this->pricesEqual((float) $effectivePeriod->price, $price)) {
            return;
        }

        $exactPeriod = $periods->first(
            fn (object $period): bool => $period->valid_from === $effectiveDate->toDateString(),
        );
        if ($exactPeriod !== null) {
            DB::table('procedure_company_prices')
                ->where('id', $exactPeriod->id)
                ->update([
                    'price' => $price,
                    'changed_by' => $changedBy,
                    'updated_at' => $recordedAt,
                ]);

            return;
        }

        $next = $periods
            ->filter(fn (object $period): bool => $period->valid_from !== null
                && $period->valid_from > $effectiveDate->toDateString())
            ->sortBy('valid_from')
            ->first();

        $previous = $periods
            ->filter(fn (object $period): bool => $period->valid_from === null
                || $period->valid_from < $effectiveDate->toDateString())
            ->sortBy(fn (object $period): string => $period->valid_from ?? '0000-00-00')
            ->last();

        if ($previous !== null) {
            DB::table('procedure_company_prices')
                ->where('id', $previous->id)
                ->update([
                    'valid_to' => $effectiveDate->subDay()->toDateString(),
                ]);
        }

        DB::table('procedure_company_prices')->insert([
            'procedure_id' => $procedureId,
            'insurance_company_id' => $insuranceCompanyId,
            'company_id' => $companyId,
            'price' => $price,
            'valid_from' => $effectiveDate->toDateString(),
            'valid_to' => $next === null
                ? null
                : CarbonImmutable::parse($next->valid_from)->subDay()->toDateString(),
            'changed_by' => $changedBy,
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ]);
    }

    private function currentPriceEquals(
        int $procedureId,
        int $insuranceCompanyId,
        int $companyId,
        float $price,
    ): bool {
        $periods = DB::table('procedure_company_prices')
            ->where('procedure_id', $procedureId)
            ->where('insurance_company_id', $insuranceCompanyId)
            ->where('company_id', $companyId)
            ->get();
        $current = $this->periodForDate($periods, CarbonImmutable::today());

        return $current !== null && $this->pricesEqual((float) $current->price, $price);
    }

    private function pricesForDate(int $procedureId, int $companyId, CarbonImmutable $date): Collection
    {
        $periods = DB::table('procedure_company_prices')
            ->where('procedure_id', $procedureId)
            ->where('company_id', $companyId)
            ->get();

        $resolved = collect();
        foreach ($periods->groupBy('insurance_company_id') as $insuranceCompanyId => $companyPeriods) {
            $period = $this->periodForDate($companyPeriods, $date);
            if ($period !== null) {
                $resolved->put((int) $insuranceCompanyId, $period);
            }
        }

        return $resolved;
    }

    private function periodForDate(Collection $periods, CarbonImmutable $date): ?object
    {
        $dateString = $date->toDateString();
        $matches = $periods->filter(
            fn (object $period): bool => ($period->valid_from === null || $period->valid_from <= $dateString)
                && ($period->valid_to === null || $period->valid_to >= $dateString),
        );

        if ($matches->count() > 1) {
            throw new LogicException('Overlapping procedure price periods were found.');
        }

        return $matches->first();
    }

    private function pricesEqual(float $left, float $right): bool
    {
        return round($left, 2) === round($right, 2);
    }
}
