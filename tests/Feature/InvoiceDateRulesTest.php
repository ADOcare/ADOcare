<?php

namespace Tests\Feature;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Support\InvoiceDates;
use Carbon\CarbonImmutable;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class InvoiceDateRulesTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_new_invoice_dates_use_the_real_creation_day(): void
    {
        $reference = CarbonImmutable::parse('2026-11-10 18:00:00', 'Europe/Bratislava');

        self::assertSame([
            'issued_at' => '2026-11-10',
            'due_date' => '2026-12-10',
        ], InvoiceDates::automaticDates('2026-02', $reference));
    }

    public function test_store_request_replaces_supplied_dates_with_automatic_dates(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10', 'Europe/Bratislava'));
        $request = StoreInvoiceRequest::create('/api/v1/invoices', 'POST', [
            'period' => '2026-02',
            'issued_at' => '2026-03-01',
            'due_date' => '2026-03-31',
        ]);
        $prepare = new \ReflectionMethod($request, 'prepareForValidation');
        $prepare->invoke($request);

        self::assertSame('2026-11-10', $request->input('issued_at'));
        self::assertSame('2026-12-10', $request->input('due_date'));
    }

    public function test_update_request_does_not_allow_calculated_dates_to_change(): void
    {
        $rules = (new UpdateInvoiceRequest)->rules();

        self::assertArrayNotHasKey('issued_at', $rules);
        self::assertArrayNotHasKey('due_date', $rules);
    }

    public function test_only_regular_monthly_invoices_use_the_delivery_deadline(): void
    {
        self::assertTrue(InvoiceDates::requiresMonthlyIssueDeadline('procedures', 'regular'));
        self::assertTrue(InvoiceDates::requiresMonthlyIssueDeadline('transport', 'combined'));
        self::assertFalse(InvoiceDates::requiresMonthlyIssueDeadline('procedures', 'corrective'));
        self::assertFalse(InvoiceDates::requiresMonthlyIssueDeadline('transport', 'additive'));
        self::assertFalse(InvoiceDates::requiresMonthlyIssueDeadline('credit_note', 'regular'));
        self::assertFalse(InvoiceDates::requiresMonthlyIssueDeadline('debit_note', 'regular'));
    }

    public function test_regular_invoice_is_rejected_after_the_fifteen_day_deadline(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10', 'Europe/Bratislava'));

        $validator = $this->dateValidator([
            'period' => '2026-09',
            'type' => 'procedures',
            'batch_mode' => 'regular',
            'issued_at' => '2026-11-10',
            'due_date' => '2026-12-10',
        ]);

        self::assertTrue($validator->fails());
        self::assertSame(
            'Faktúra musí byť vystavená najneskôr 15.10.2026.',
            $validator->errors()->first('issued_at'),
        );
    }

    public function test_corrective_invoice_uses_today_for_an_older_period(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10', 'Europe/Bratislava'));

        $validator = $this->dateValidator([
            'period' => '2026-02',
            'type' => 'procedures',
            'batch_mode' => 'corrective',
            'issued_at' => '2026-11-10',
            'due_date' => '2026-12-10',
        ]);

        self::assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_new_corrective_invoice_cannot_be_backdated(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10', 'Europe/Bratislava'));

        $validator = $this->dateValidator([
            'period' => '2026-02',
            'type' => 'procedures',
            'batch_mode' => 'corrective',
            'issued_at' => '2026-11-09',
            'due_date' => '2026-12-10',
        ]);

        self::assertTrue($validator->fails());
        self::assertSame(
            'Dátum vystavenia nového dokladu musí byť dnešný deň 10.11.2026.',
            $validator->errors()->first('issued_at'),
        );
    }

    private function dateValidator(array $data)
    {
        $request = StoreInvoiceRequest::create('/api/v1/invoices', 'POST', $data);
        $validator = (new Factory(new Translator(new ArrayLoader, 'sk')))->make($data, [
            'issued_at' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $request->withValidator($validator);

        return $validator;
    }
}
