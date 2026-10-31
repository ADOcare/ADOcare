<?php

namespace App\Jobs;

use App\Enums\VertexTrainingRunStatus;
use App\Jobs\Concerns\FailsVertexTrainingRun;
use App\Models\VertexTrainingRun;
use App\Services\Vertex\Training\VertexTrainingTasks;
use App\Services\Vertex\VertexAutotrainStateService;
use App\Services\Vertex\VertexInferenceClient;
use App\Services\Vertex\VertexModelPromotionService;
use App\Services\Vertex\VertexTrainingRunNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PostPromotionHealthCheckJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use FailsVertexTrainingRun;

    public int $tries = 3;

    public function __construct(public int $runId)
    {
    }

    public function handle(
        VertexAutotrainStateService $stateService,
        VertexInferenceClient $inferenceClient,
        VertexModelPromotionService $promotionService,
        VertexTrainingRunNotifier $notifier,
        VertexTrainingTasks $tasks
    ): void {
        $run = VertexTrainingRun::query()->find($this->runId);

        if (! $run || $run->status !== VertexTrainingRunStatus::Promoted->value) {
            return;
        }

        $task = $tasks->get((string) $run->pipeline);
        $state = $stateService->read($task->pipeline());
        $location = trim((string) ($state['active_location'] ?? ''));
        $endpointId = trim((string) ($state['active_endpoint_id'] ?? ''));

        if ($location === '' || $endpointId === '') {
            $promotionService->rollback($run, 'Post-promotion health check zlyhal: chýba aktívny endpoint v state.');
            return;
        }

        try {
            $response = $inferenceClient->invokeEndpoint($location, $endpointId, $task->smokePrompt(), $task->systemInstruction());
            $text = trim((string) data_get($response, 'candidates.0.content.parts.0.text', ''));
            $decoded = json_decode($text, true);

            if (! is_array($decoded) || str_contains($text, '```') || ! $task->isValidOutput($decoded)) {
                $promotionService->rollback($run, 'Post-promotion health check zlyhal: neplatná odpoveď kandidáta.');
                return;
            }
        } catch (\Throwable $e) {
            $promotionService->rollback($run, 'Post-promotion health check zlyhal: ' . $e->getMessage());
            return;
        }

        $run->completed_at = now();
        $run->save();

        $notifier->notify('health_check_passed', $run);
    }
}
