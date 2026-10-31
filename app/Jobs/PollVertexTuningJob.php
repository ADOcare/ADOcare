<?php

namespace App\Jobs;

use App\Enums\VertexTrainingRunStatus;
use App\Jobs\Concerns\FailsVertexTrainingRun;
use App\Models\VertexTrainingRun;
use App\Services\Vertex\VertexTuningService;
use App\Services\Vertex\VertexTrainingRunNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PollVertexTuningJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use FailsVertexTrainingRun;

    public int $tries = 100;

    public function __construct(public int $runId)
    {
    }

    public function handle(VertexTuningService $tuningService, VertexTrainingRunNotifier $notifier): void
    {
        $run = VertexTrainingRun::query()->find($this->runId);

        if (! $run || $run->status !== VertexTrainingRunStatus::Training->value) {
            return;
        }

        $jobName = trim((string) $run->tuning_job_name);
        if ($jobName === '') {
            $run->status = VertexTrainingRunStatus::Failed->value;
            $run->failure_stage = 'polling';
            $run->failure_message = 'Chýba tuning job name.';
            $run->failed_at = now();
            $run->save();
            $notifier->notify('training_failed', $run);
            return;
        }

        $maxHours = (int) config('services.vertex_ai.auto_train.max_hours', 48);
        if ($run->started_at && $run->started_at->addHours($maxHours)->isPast()) {
            $tuningService->cancelTuningJob($jobName);
            $run->status = VertexTrainingRunStatus::Failed->value;
            $run->failure_stage = 'polling_timeout';
            $run->failure_message = 'Tuning job prekročil maximálny čas čakania.';
            $run->failed_at = now();
            $run->save();
            $notifier->notify('training_timeout', $run);
            return;
        }

        try {
            $job = $tuningService->getTuningJob($jobName);
        } catch (\Throwable $e) {
            PollVertexTuningJob::dispatch($run->id)->delay(now()->addMinutes((int) config('services.vertex_ai.auto_train.poll_minutes', 15)));
            return;
        }

        $state = strtoupper((string) data_get($job, 'state', ''));

        if (in_array($state, ['JOB_STATE_SUCCEEDED', 'SUCCEEDED'], true)) {
            try {
                $deployment = $tuningService->resolveCompletedDeployment($job);
            } catch (\Throwable $e) {
                $run->status = VertexTrainingRunStatus::Failed->value;
                $run->failure_stage = 'deployment_resolution';
                $run->failure_message = $e->getMessage();
                $run->failed_at = now();
                $run->save();
                $notifier->notify('deployment_resolution_failed', $run);
                return;
            }

            $run->status = VertexTrainingRunStatus::TrainingSucceeded->value;
            $run->new_model_name = $deployment['model_name'];
            $run->new_endpoint_name = $deployment['endpoint_name'];
            $run->new_endpoint_id = $deployment['endpoint_id'];
            $run->new_location = $deployment['location'];
            $run->metadata = array_merge(is_array($run->metadata) ? $run->metadata : [], ['completed_job' => $job]);
            $run->save();

            $notifier->notify('training_succeeded', $run);

            EvaluateVertexCandidateJob::dispatch($run->id);
            return;
        }

        if (in_array($state, ['JOB_STATE_FAILED', 'FAILED', 'JOB_STATE_CANCELLED', 'CANCELLED'], true)) {
            $run->status = VertexTrainingRunStatus::Failed->value;
            $run->failure_stage = 'training';
            $run->failure_message = 'Vertex tuning job skončil stavom: ' . $state;
            $run->failed_at = now();
            $run->metadata = array_merge(is_array($run->metadata) ? $run->metadata : [], ['failed_job' => $job]);
            $run->save();
            $notifier->notify('training_failed', $run);
            return;
        }

        PollVertexTuningJob::dispatch($run->id)->delay(now()->addMinutes((int) config('services.vertex_ai.auto_train.poll_minutes', 15)));
    }
}
