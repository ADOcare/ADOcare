<?php

namespace App\Jobs;

use App\Enums\VertexTrainingRunStatus;
use App\Jobs\Concerns\FailsVertexTrainingRun;
use App\Models\VertexTrainingRun;
use App\Services\Vertex\VertexCandidateEvaluationService;
use App\Services\Vertex\VertexTrainingRunNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class EvaluateVertexCandidateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use FailsVertexTrainingRun;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $runId)
    {
    }

    public function handle(VertexCandidateEvaluationService $evaluationService, VertexTrainingRunNotifier $notifier): void
    {
        $run = VertexTrainingRun::query()->find($this->runId);

        if (
            ! $run
            || ! in_array($run->status, [
                VertexTrainingRunStatus::TrainingSucceeded->value,
                VertexTrainingRunStatus::Evaluating->value,
            ], true)
        ) {
            return;
        }

        if ($run->status !== VertexTrainingRunStatus::Evaluating->value) {
            $run->status = VertexTrainingRunStatus::Evaluating->value;
            $run->save();
        }

        try {
            $evaluation = $evaluationService->evaluate($run);
        } catch (\Throwable $e) {
            $run->status = VertexTrainingRunStatus::Failed->value;
            $run->failure_stage = 'evaluation';
            $run->failure_message = $e->getMessage();
            $run->failed_at = Carbon::now();
            $run->save();
            $notifier->notify('evaluation_failed', $run);
            return;
        }

        $run->current_score = (float) ($evaluation['current_score'] ?? 0);
        $run->candidate_score = (float) ($evaluation['candidate_score'] ?? 0);
        $run->json_validity_rate = (float) data_get($evaluation, 'candidate.valid_json_rate', 0.0);
        $run->required_fields_rate = (float) data_get($evaluation, 'candidate.required_fields_rate', 0.0);
        $run->http_failures = (int) data_get($evaluation, 'candidate.http_failures', 0);
        $run->critical_errors = (int) data_get($evaluation, 'candidate.critical_error_count', 0);
        $run->average_latency_ms = (float) data_get($evaluation, 'candidate.average_latency_ms', 0.0);
        $run->metadata = array_merge(is_array($run->metadata) ? $run->metadata : [], ['evaluation' => $evaluation]);

        if (! (bool) ($evaluation['passes'] ?? false)) {
            $run->status = VertexTrainingRunStatus::EvaluationFailed->value;
            $run->failure_stage = 'evaluation';
            $run->failure_message = 'Kandidát neprešiel bezpečnostnými a kvalitativnými prahmi.';
            $run->failed_at = Carbon::now();
            $run->save();
            $notifier->notify('evaluation_rejected', $run);
            return;
        }

        $run->status = VertexTrainingRunStatus::ReadyForPromotion->value;
        $run->save();

        $notifier->notify('ready_for_promotion', $run);

        PromoteVertexCandidateJob::dispatch($run->id);
    }
}
