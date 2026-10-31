<?php

namespace App\Jobs\Concerns;

use App\Enums\VertexTrainingRunStatus;
use App\Models\VertexTrainingRun;
use App\Services\Vertex\VertexTrainingRunNotifier;
use Throwable;

/**
 * Marks the training run as failed and emails the team when a pipeline job crashes after all retries.
 */
trait FailsVertexTrainingRun
{
    public function failed(Throwable $exception): void
    {
        $run = VertexTrainingRun::query()->find($this->runId);

        if (! $run || ! in_array($run->status, [VertexTrainingRunStatus::Pending->value, ...VertexTrainingRunStatus::activeStatuses()], true)) {
            return;
        }

        $run->status = VertexTrainingRunStatus::Failed->value;
        $run->failure_stage = $run->failure_stage ?: class_basename(static::class);
        $run->failure_message = mb_substr($exception->getMessage(), 0, 1000);
        $run->failed_at = now();
        $run->save();

        app(VertexTrainingRunNotifier::class)->notify('job_crashed', $run);
    }
}
