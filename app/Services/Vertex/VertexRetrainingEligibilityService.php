<?php

namespace App\Services\Vertex;

use App\Enums\VertexTrainingRunStatus;
use App\Models\VertexTrainingRun;
use App\Services\Vertex\Training\VertexTrainingTask;
use Carbon\Carbon;

class VertexRetrainingEligibilityService
{
    public function __construct(private readonly VertexTrainingDatasetService $datasets) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(VertexTrainingTask $task, bool $force = false): array
    {
        if (! (bool) config('services.vertex_ai.auto_train.enabled', false)) {
            return $this->skip('disabled', 'Automatické retrénovanie je vypnuté (VERTEX_AUTOTRAIN_ENABLED).');
        }

        $day = (int) config('services.vertex_ai.auto_train.day', 1);
        if (! $force && (int) now()->day !== $day) {
            return $this->skip('outside_schedule_day', 'Dnes nie je plánovaný mesačný deň retrénovania.');
        }

        if ($task->staticEndpointId() === '' || $task->location() === '') {
            return $this->skip('missing_static_endpoint', "Chýba statický endpoint alebo lokalita pre pipeline {$task->pipeline()}.");
        }

        $hasActiveRun = VertexTrainingRun::query()
            ->where('pipeline', $task->pipeline())
            ->whereIn('status', VertexTrainingRunStatus::activeStatuses())
            ->exists();

        if ($hasActiveRun) {
            return $this->skip('active_run_exists', 'Retrénovanie už prebieha v inom behu.');
        }

        $newExampleCount = $this->datasets->countNewExamples($task);
        $minimum = (int) config('services.vertex_ai.auto_train.min_new_feedback', 25);

        if (! $force && $newExampleCount < $minimum) {
            return $this->skip(
                'insufficient_examples',
                'Nedostatok nových schválených príkladov pre retrénovanie.',
                ['new_examples' => $newExampleCount, 'required_examples' => $minimum]
            );
        }

        return [
            'can_start' => true,
            'reason' => 'eligible',
            'message' => 'Retrénovanie môže byť spustené.',
            'new_examples' => $newExampleCount,
            'required_examples' => $minimum,
            'pipeline' => $task->pipeline(),
            'started_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Skips that happen on the scheduled day and need human attention.
     *
     * @param  array<string, mixed>  $result
     */
    public function isReportableSkip(array $result): bool
    {
        return ! ($result['can_start'] ?? false)
            && ! in_array($result['reason'] ?? '', ['disabled', 'outside_schedule_day'], true);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function skip(string $reason, string $message, array $context = []): array
    {
        return [
            'can_start' => false,
            'reason' => $reason,
            'message' => $message,
            ...$context,
        ];
    }
}
