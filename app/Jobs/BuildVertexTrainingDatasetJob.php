<?php

namespace App\Jobs;

use App\Enums\VertexTrainingRunStatus;
use App\Jobs\Concerns\FailsVertexTrainingRun;
use App\Exceptions\InsufficientTrainingDataException;
use App\Models\VertexTrainingRun;
use App\Services\Vertex\VertexTrainingDatasetService;
use App\Services\Vertex\VertexTrainingRunNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BuildVertexTrainingDatasetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use FailsVertexTrainingRun;

    public int $tries = 3;

    public function __construct(
        public int $runId,
        public bool $force = false
    ) {
    }

    public function handle(VertexTrainingDatasetService $datasetService, VertexTrainingRunNotifier $notifier): void
    {
        $run = VertexTrainingRun::query()->find($this->runId);

        if (! $run || $run->status !== VertexTrainingRunStatus::Pending->value) {
            return;
        }

        $run->status = VertexTrainingRunStatus::BuildingDataset->value;
        $run->save();

        try {
            $built = $datasetService->buildForRun($run, $this->force);
        } catch (InsufficientTrainingDataException $e) {
            $run->status = VertexTrainingRunStatus::Skipped->value;
            $run->failure_stage = 'dataset_build';
            $run->failure_message = $e->getMessage();
            $run->failed_at = now();
            $run->save();

            $notifier->notify('dataset_skipped', $run);

            return;
        } catch (\Throwable $e) {
            report($e);

            $run->status = VertexTrainingRunStatus::Failed->value;
            $run->failure_stage = 'dataset_build';
            $run->failure_message = mb_substr($e->getMessage(), 0, 1000);
            $run->failed_at = now();
            $run->save();

            $notifier->notify('dataset_build_failed', $run);

            return;
        }

        $idempotencyKey = hash(
            'sha256',
            $run->pipeline . '|' . (string) ($built['dataset_hash'] ?? '') . '|' . (string) config('services.vertex_ai.auto_train.base_model', '')
        );

        $existing = VertexTrainingRun::query()
            ->where('pipeline', $run->pipeline)
            ->where('idempotency_key', $idempotencyKey)
            ->whereIn('status', [
                VertexTrainingRunStatus::TrainingRequested->value,
                VertexTrainingRunStatus::Training->value,
                VertexTrainingRunStatus::TrainingSucceeded->value,
                VertexTrainingRunStatus::Evaluating->value,
                VertexTrainingRunStatus::ReadyForPromotion->value,
                VertexTrainingRunStatus::Promoted->value,
            ])
            ->where('id', '!=', $run->id)
            ->first();

        if ($existing) {
            $run->status = VertexTrainingRunStatus::Skipped->value;
            $run->failure_stage = 'idempotency';
            $run->failure_message = 'Rovnaký dataset bol už spracovaný v behu #' . $existing->id . '.';
            $run->metadata = array_merge(is_array($run->metadata) ? $run->metadata : [], [
                'duplicate_of_run_id' => $existing->id,
                'dataset_hash' => $built['dataset_hash'] ?? null,
            ]);
            $run->failed_at = now();
            $run->save();

            $notifier->notify('dataset_duplicate_skipped', $run);

            return;
        }

        // Free the key from earlier failed attempts so this retry can claim it.
        VertexTrainingRun::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('id', '!=', $run->id)
            ->update(['idempotency_key' => null]);

        $run->status = VertexTrainingRunStatus::DatasetReady->value;
        $run->version = (string) ($built['version'] ?? $run->version);
        $run->idempotency_key = $idempotencyKey;
        $run->training_dataset_uri = (string) ($built['training_dataset_uri'] ?? null);
        $run->validation_dataset_uri = (string) ($built['validation_dataset_uri'] ?? null);
        $run->dataset_hash = (string) ($built['dataset_hash'] ?? null);
        $run->training_examples_count = (int) ($built['training_examples_count'] ?? 0);
        $run->validation_examples_count = (int) ($built['validation_examples_count'] ?? 0);
        $run->metadata = array_merge(is_array($run->metadata) ? $run->metadata : [], $built);
        $run->save();

        $notifier->notify('dataset_ready', $run);

        StartVertexTuningJob::dispatch($run->id);
    }
}
