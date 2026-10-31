<?php

namespace App\Services\Vertex;

use App\Enums\VertexTrainingRunStatus;
use App\Models\VertexTrainingRun;
use App\Services\Vertex\Training\VertexTrainingTasks;
use Illuminate\Support\Facades\Cache;

class VertexModelPromotionService
{
    public function __construct(
        private readonly VertexAutotrainStateService $stateService,
        private readonly VertexTrainingRunNotifier $notifier,
        private readonly VertexTrainingTasks $tasks
    ) {
    }

    /**
     * @param array<string, string> $deployment
     * @param array<string, mixed> $evaluation
     */
    public function promote(VertexTrainingRun $run, array $deployment, array $evaluation): void
    {
        $task = $this->tasks->get((string) $run->pipeline);
        $lock = Cache::lock('vertex-retraining:'.$task->pipeline().':promotion', 300);

        if (! $lock->get()) {
            throw new \RuntimeException('Promócia modelu je práve uzamknutá iným procesom.');
        }

        try {
            $state = $this->stateService->read($task->pipeline());

            $newState = [
                'schema_version' => 1,
                'pipeline' => $task->pipeline(),
                'base_model' => (string) data_get($run->metadata, 'base_model', config('services.vertex_ai.auto_train.base_model')),
                'active_model_name' => $deployment['model_name'] ?? '',
                'active_endpoint_name' => $deployment['endpoint_name'] ?? '',
                'active_endpoint_id' => $deployment['endpoint_id'] ?? '',
                'active_location' => $deployment['location'] ?? '',
                'previous_model_name' => (string) ($state['active_model_name'] ?? ''),
                'previous_endpoint_name' => (string) ($state['active_endpoint_name'] ?? ''),
                'previous_endpoint_id' => (string) ($state['active_endpoint_id'] ?? $task->staticEndpointId()),
                'previous_location' => (string) ($state['active_location'] ?? $task->location()),
                'training_run_id' => $run->id,
                'dataset_version' => (string) $run->version,
                'dataset_hash' => (string) $run->dataset_hash,
                'candidate_score' => (float) ($evaluation['candidate_score'] ?? 0.0),
                'activated_at' => now()->toIso8601String(),
            ];

            $this->stateService->writeAtomic($newState, $task->pipeline());

            $run->status = VertexTrainingRunStatus::Promoted->value;
            $run->promoted_at = now();
            $run->completed_at = now();
            $run->save();

            $this->notifier->notify('promoted', $run);
        } finally {
            optional($lock)->release();
        }
    }

    public function rollback(VertexTrainingRun $run, string $reason): void
    {
        $task = $this->tasks->get((string) $run->pipeline);
        $lock = Cache::lock('vertex-retraining:'.$task->pipeline().':promotion', 300);

        if (! $lock->get()) {
            return;
        }

        try {
            $state = $this->stateService->read($task->pipeline());

            $previousModel = trim((string) ($state['previous_model_name'] ?? ''));
            $previousEndpointName = trim((string) ($state['previous_endpoint_name'] ?? ''));
            $previousEndpointId = trim((string) ($state['previous_endpoint_id'] ?? $task->staticEndpointId()));
            $previousLocation = trim((string) ($state['previous_location'] ?? $task->location()));

            $rollbackState = [
                ...$state,
                'active_model_name' => $previousModel,
                'active_endpoint_name' => $previousEndpointName,
                'active_endpoint_id' => $previousEndpointId,
                'active_location' => $previousLocation,
                'rolled_back_at' => now()->toIso8601String(),
                'rolled_back_run_id' => $run->id,
                'rollback_reason' => $reason,
            ];

            $this->stateService->writeAtomic($rollbackState, $task->pipeline());

            $run->status = VertexTrainingRunStatus::RolledBack->value;
            $run->failed_at = now();
            $run->failure_stage = 'post_promotion_health';
            $run->failure_message = $reason;
            $run->save();

            $this->notifier->notify('rolled_back', $run, [
                'message' => $reason,
            ]);
        } finally {
            optional($lock)->release();
        }
    }
}
