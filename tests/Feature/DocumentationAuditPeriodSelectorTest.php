<?php

namespace Tests\Feature;

use App\Services\DocumentationAudit\DocumentationAuditPeriodSelector;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class DocumentationAuditPeriodSelectorTest extends TestCase
{
    private Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container();
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository([
            'documentation_audit' => [
                'start_date' => '2026-08-01',
                'grace_days' => 5,
            ],
        ]));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_closed_month_becomes_eligible_day_after_grace_period(): void
    {
        $selector = new DocumentationAuditPeriodSelector();

        self::assertFalse($selector->isEligible('2026-09', CarbonImmutable::parse('2026-10-05')));
        self::assertTrue($selector->isEligible('2026-09', CarbonImmutable::parse('2026-10-06')));
    }

    public function test_current_month_and_periods_before_start_date_are_not_eligible(): void
    {
        $selector = new DocumentationAuditPeriodSelector();
        $today = CarbonImmutable::parse('2026-10-15');

        self::assertFalse($selector->isEligible('2026-10', $today));
        self::assertFalse($selector->isEligible('2026-07', $today));
        self::assertSame('2026-09', $selector->latestEligiblePeriod($today));
    }
}