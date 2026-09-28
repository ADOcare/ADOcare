<?php

namespace Tests\Unit\Claims;

use App\Services\Claims\ClaimInterfaceVersionRegistry;
use DomainException;
use PHPUnit\Framework\TestCase;

class ClaimInterfaceVersionRegistryTest extends TestCase
{
    public function test_versions_are_selected_by_service_date(): void
    {
        $registry = new ClaimInterfaceVersionRegistry();

        self::assertSame('F-396/7', $registry->forDate('753d', '2026-04-01'));
        self::assertSame('F-372/9', $registry->forDate('793n', '2026-06-30'));
        self::assertSame('F-372/10', $registry->forDate('793n', '2026-07-01'));
    }

    public function test_unknown_historical_753d_is_not_guessed(): void
    {
        $this->expectException(DomainException::class);
        (new ClaimInterfaceVersionRegistry())->forDate('753d', '2026-03-31');
    }
}
