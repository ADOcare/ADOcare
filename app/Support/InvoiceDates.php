<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class InvoiceDates
{
    public const DEFAULT_DUE_DAYS = 30;

    public static function deliveryDate(string $period): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $period, 'Europe/Bratislava')
            ->endOfMonth()
            ->startOfDay();
    }

    public static function latestIssueDate(string $period): CarbonImmutable
    {
        return self::deliveryDate($period)
            ->addDay()
            ->startOfMonth()
            ->addDays(14);
    }

    public static function appliesToMonthlyService(string $type): bool
    {
        return in_array($type, ['procedures', 'transport'], true);
    }

    /**
     * @return array{issued_at: string, sent_at: string, due_date: string}
     */
    public static function automaticDates(
        string $period,
        ?CarbonImmutable $referenceDate = null,
        int $dueDays = self::DEFAULT_DUE_DAYS,
    ): array {
        $referenceDate = ($referenceDate ?? CarbonImmutable::now('Europe/Bratislava'))->startOfDay();
        $deliveryDate = self::deliveryDate($period);
        $latestIssueDate = self::latestIssueDate($period);

        $issuedAt = match (true) {
            $referenceDate->lt($deliveryDate) => $deliveryDate,
            $referenceDate->gt($latestIssueDate) => $latestIssueDate,
            default => $referenceDate,
        };

        $sentAt = $referenceDate->lt($issuedAt) ? $issuedAt : $referenceDate;
        $dueDate = $sentAt->addDays($dueDays);

        return [
            'issued_at' => $issuedAt->toDateString(),
            'sent_at' => $sentAt->toDateString(),
            'due_date' => $dueDate->toDateString(),
        ];
    }
}
