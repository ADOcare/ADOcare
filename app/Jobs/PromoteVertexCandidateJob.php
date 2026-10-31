<?php

namespace App\Jobs;

use App\Enums\VertexTrainingRunStatus;
use App\Jobs\Concerns\FailsVertexTrainingRun;
use App\Models\VertexTrainingRun;
use App\Services\Vertex\VertexModelPromotionService;
use App\Services\Vertex\VertexTrainingRunNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PromoteVertexCandidateJob implements ShouldQueue
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

    public function handle(VertexModelPromotionService $promotionService, VertexTrainingRunNotifier $notifier): void
    {
        $run = VertexTrainingRun::query()->find($this->runId);

        if (! $run || $run->status !== VertexTrainingRunStatus::ReadyForPromotion->value) {
            return;
        }

        $deployment = [
            'model_name' => (string) $run->new_model_name,
            'endpoint_name' => (string) $run->new_endpoint_name,
            'endpoint_id' => (string) $run->new_endpoint_id,
            'location' => (string) $run->new_location,
        ];

        $evaluation = is_array($run->metadata) ? ($run->metadata['evaluation'] ?? []) : [];

        try {
            $promotionService->promote($run, $deployment, is_array($evaluation) ? $evaluation : []);
        } catch (\Throwable $e) {
            $run->status = VertexTrainingRunStatus::Failed->value;
            $run->failure_stage = 'promotion';
            $run->failure_message = $e->getMessage();
            $run->failed_at = now();
            $run->save();
            $notifier->notify('promotion_failed', $run);
            return;
        }

        PostPromotionHealthCheckJob::dispatch($run->id);
    }
}
