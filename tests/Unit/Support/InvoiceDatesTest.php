<?php

namespace Tests\Unit\Support;

use App\Support\InvoiceDates;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class InvoiceDatesTest extends TestCase
{
    public function test_august_service_is_delivered_on_last_day_of_august(): void
    {
        $deliveryDate = InvoiceDates::deliveryDate('2026-08');

        self::assertSame('2026-08-31', $deliveryDate->toDateString());
        self::assertSame('00:00:00', $deliveryDate->format('H:i:s'));
    }

    public function test_august_invoice_must_be_issued_by_fifteenth_of_september(): void
    {
        self::assertSame('2026-09-15', InvoiceDates::latestIssueDate('2026-08')->toDateString());
    }

    public function test_issue_on_delivery_day_is_not_treated_as_earlier(): void
    {
        $deliveryDate = InvoiceDates::deliveryDate('2026-09');
        $issuedAt = CarbonImmutable::parse('2026-09-30', 'Europe/Bratislava')->startOfDay();

        self::assertFalse($issuedAt->lt($deliveryDate));
        self::assertTrue($issuedAt->equalTo($deliveryDate));
    }

    public function test_leap_year_february_uses_actual_last_day(): void
    {
        self::assertSame('2028-02-29', InvoiceDates::deliveryDate('2028-02')->toDateString());
        self::assertSame('2028-03-15', InvoiceDates::latestIssueDate('2028-02')->toDateString());
    }

    public function test_deadline_crosses_to_next_year(): void
    {
        self::assertSame('2026-12-31', InvoiceDates::deliveryDate('2026-12')->toDateString());
        self::assertSame('2027-01-15', InvoiceDates::latestIssueDate('2026-12')->toDateString());
    }

    public function test_only_monthly_service_invoices_use_delivery_deadline(): void
    {
        self::assertTrue(InvoiceDates::appliesToMonthlyService('procedures'));
        self::assertTrue(InvoiceDates::appliesToMonthlyService('transport'));
        self::assertFalse(InvoiceDates::appliesToMonthlyService('credit_note'));
        self::assertFalse(InvoiceDates::appliesToMonthlyService('debit_note'));
    }

    public function test_late_august_invoice_is_automatically_clamped_to_legal_deadline(): void
    {
        $dates = InvoiceDates::automaticDates(
            '2026-08',
            CarbonImmutable::parse('2026-09-28', 'Europe/Bratislava')
        );

        self::assertSame('2026-09-15', $dates['issued_at']);
        self::assertSame('2026-09-28', $dates['sent_at']);
        self::assertSame('2026-10-28', $dates['due_date']);
    }

    public function test_invoice_created_within_deadline_uses_actual_reference_date(): void
    {
        $dates = InvoiceDates::automaticDates(
            '2026-08',
            CarbonImmutable::parse('2026-09-05', 'Europe/Bratislava')
        );

        self::assertSame('2026-09-05', $dates['issued_at']);
        self::assertSame('2026-09-05', $dates['sent_at']);
        self::assertSame('2026-10-05', $dates['due_date']);
    }
}
