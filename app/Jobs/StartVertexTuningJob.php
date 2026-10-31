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

class StartVertexTuningJob implements ShouldQueue
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

    public function handle(VertexTuningService $tuningService, VertexTrainingRunNotifier $notifier): void
    {
        $run = VertexTrainingRun::query()->find($this->runId);

        if (! $run || ! in_array($run->status, [VertexTrainingRunStatus::DatasetReady->value, VertexTrainingRunStatus::TrainingRequested->value], true)) {
            return;
        }

        if (trim((string) $run->tuning_job_name) !== '') {
            $run->status = VertexTrainingRunStatus::Training->value;
            $run->save();

            $notifier->notify('training_polling_resumed', $run);

            PollVertexTuningJob::dispatch($run->id)->delay(now()->addMinutes((int) config('services.vertex_ai.auto_train.poll_minutes', 15)));
            return;
        }

        $run->status = VertexTrainingRunStatus::TrainingRequested->value;
        $run->save();

        try {
            $jobName = $tuningService->createTuningJob($run);
        } catch (\Throwable $e) {
            $run->status = VertexTrainingRunStatus::Failed->value;
            $run->failure_stage = 'training_requested';
            $run->failure_message = $e->getMessage();
            $run->failed_at = now();
            $run->save();

            $notifier->notify('training_request_failed', $run);
            return;
        }

        $run->status = VertexTrainingRunStatus::Training->value;
        $run->tuning_job_name = $jobName;
        $run->metadata = array_merge(is_array($run->metadata) ? $run->metadata : [], ['tuning_job_name' => $jobName, 'base_model' => $tuningService->baseModel()]);
        $run->save();

        $notifier->notify('training_started', $run);

        PollVertexTuningJob::dispatch($run->id)->delay(now()->addMinutes((int) config('services.vertex_ai.auto_train.poll_minutes', 15)));
    }
}
