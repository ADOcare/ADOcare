<?php

namespace App\Console\Commands;

use App\Models\VertexTrainingRun;
use Illuminate\Console\Command;

class VertexTrainingRunStatusCommand extends Command
{
    protected $signature = 'vertex:run-status {--id= : Konkrétne ID behu} {--pipeline=dekurz : dekurz alebo proposal}';

    protected $description = 'Zobrazí posledný alebo konkrétny Vertex training run';

    public function handle(): int
    {
        $id = $this->option('id');

        $query = VertexTrainingRun::query()->where('pipeline', (string) $this->option('pipeline'))->orderByDesc('id');
        if ($id !== null) {
            $query->where('id', (int) $id);
        }

        $run = $query->first();

        if (! $run) {
            $this->warn('Nenašiel sa žiadny training run.');
            return self::SUCCESS;
        }

        $this->line('Run ID: ' . $run->id);
        $this->line('Pipeline: ' . $run->pipeline);
        $this->line('Status: ' . $run->status);
        $this->line('Failure: ' . (string) $run->failure_message);
        $this->line('Version: ' . (string) $run->version);
        $this->line('Tuning job: ' . (string) $run->tuning_job_name);
        $this->line('Dataset hash: ' . (string) $run->dataset_hash);
        $this->line('Candidate endpoint: ' . (string) $run->new_endpoint_id);

        return self::SUCCESS;
    }
}
