<?php

namespace App\Console\Commands;

use App\Jobs\StartMonthlyVertexRetrainingJob;
use App\Services\Vertex\Training\VertexTrainingTasks;
use App\Services\Vertex\VertexRetrainingEligibilityService;
use App\Services\Vertex\VertexTrainingRunNotifier;
use Illuminate\Console\Command;

class RunMonthlyVertexRetraining extends Command
{
    protected $signature = 'vertex:monthly-retrain
        {--pipeline=* : Pipeline na retrénovanie (dekurz, proposal); predvolene všetky}
        {--force : Preskočí mesačný deň a minimálny počet nových príkladov}
        {--dry-run : Vykoná iba kontrolu spustiteľnosti bez spustenia retrénovania}';

    protected $description = 'Mesačne spustí bezpečný retraining pipeline pre Vertex modely adocare-dekurz a adocare-proposal';

    public function __construct(
        private readonly VertexRetrainingEligibilityService $eligibility,
        private readonly VertexTrainingTasks $tasks,
        private readonly VertexTrainingRunNotifier $notifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $pipelines = $this->option('pipeline') ?: $this->tasks->pipelines();

        foreach ($pipelines as $pipeline) {
            $task = $this->tasks->get((string) $pipeline);
            $this->line("<options=bold>[{$task->label()}]</>");

            $result = $this->eligibility->evaluate($task, $force);

            if (isset($result['new_examples'], $result['required_examples'])) {
                $this->line('Nové príklady: '.$result['new_examples'].' / '.$result['required_examples']);
            }

            if (! (bool) ($result['can_start'] ?? false)) {
                $this->warn((string) ($result['message'] ?? 'Retrénovanie bolo preskočené.'));

                if (! $dryRun && $this->eligibility->isReportableSkip($result)) {
                    $this->notifier->notifySkipped($task, $result);
                }

                continue;
            }

            $this->info((string) ($result['message'] ?? 'Retrénovanie je spustiteľné.'));

            if ($dryRun) {
                $this->info('Dry-run dokončený. Tréning nebol spustený.');

                continue;
            }

            StartMonthlyVertexRetrainingJob::dispatch($result, $force, $task->pipeline());
            $this->info('Retraining job bol odoslaný do fronty.');
        }

        return self::SUCCESS;
    }
}
