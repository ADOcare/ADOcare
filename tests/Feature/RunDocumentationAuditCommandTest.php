<?php

namespace Tests\Feature;

use App\Console\Commands\RunDocumentationAudit;
use App\Services\DocumentationAudit\DocumentationAuditService;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class RunDocumentationAuditCommandTest extends TestCase
{
    public function test_command_outputs_concise_statistics_and_forwards_explicit_scope(): void
    {
        $service = $this->createMock(DocumentationAuditService::class);
        $service->expects(self::once())
            ->method('run')
            ->with('2026-09', null, 668, 12, true)
            ->willReturn([
                'disabled' => false,
                'dry_run' => true,
                'periods_checked' => 1,
                'patients_checked' => 1,
                'points_checked' => 14,
                'detected' => ['DOC-001' => 2, 'DOC-002' => 1, 'DOC-003' => 0, 'DOC-004' => 0, 'DOC-005' => 0],
                'opened' => 2,
                'reopened' => 1,
                'still_open' => 0,
                'resolved' => 1,
                'diagnostics' => [],
            ]);

        $app = new Application(dirname(__DIR__, 2));
        $app->instance(DocumentationAuditService::class, $service);
        $command = new RunDocumentationAudit();
        $command->setLaravel($app);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--month' => '2026-09',
            '--patient' => '668',
            '--company' => '12',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Documentation audit (dry run)', $tester->getDisplay());
        self::assertStringContainsString('DOC-001: 2', $tester->getDisplay());
        self::assertStringContainsString('Resolved: 1', $tester->getDisplay());
    }

    public function test_command_rejects_conflicting_or_invalid_period_options(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->instance(DocumentationAuditService::class, $this->createMock(DocumentationAuditService::class));
        $command = new RunDocumentationAudit();
        $command->setLaravel($app);

        $conflict = new CommandTester($command);
        self::assertSame(2, $conflict->execute(['--month' => '2026-09', '--from' => '2026-08']));

        $invalid = new CommandTester($command);
        self::assertSame(2, $invalid->execute(['--month' => '09/2026']));
    }
}