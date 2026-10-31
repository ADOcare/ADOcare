<?php

namespace App\Services\Vertex;

use App\Models\VertexTrainingRun;
use App\Notifications\VertexTrainingRunStatusNotification;
use App\Services\Vertex\Training\VertexTrainingTask;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class VertexTrainingRunNotifier
{
    /**
     * Intermediate progress events; only the start, the final outcome and failures are emailed.
     */
    private const SILENT_EVENTS = [
        'run_started',
        'dataset_ready',
        'training_polling_resumed',
        'training_succeeded',
        'ready_for_promotion',
        'promoted',
    ];

    /**
     * @param  array<string, mixed>  $context
     */
    public function notify(string $event, VertexTrainingRun $run, array $context = []): void
    {
        $this->send($event, array_merge([
            'pipeline' => (string) $run->pipeline,
            'run_id' => $run->id,
            'dataset_version' => (string) $run->version,
            'new_examples' => (int) ($run->training_examples_count ?? 0),
            'tuning_job_name' => (string) ($run->tuning_job_name ?? ''),
            'previous_endpoint' => (string) ($run->previous_endpoint_id ?? ''),
            'candidate_endpoint' => (string) ($run->new_endpoint_id ?? ''),
            'status' => (string) $run->status,
            'candidate_score' => (string) ($run->candidate_score ?? ''),
            'current_score' => (string) ($run->current_score ?? ''),
            'failure_stage' => (string) ($run->failure_stage ?? ''),
            'message' => (string) ($run->failure_message ?? ''),
        ], $context));
    }

    /**
     * Report a scheduled retraining that could not start (no run is created).
     *
     * @param  array<string, mixed>  $result
     */
    public function notifySkipped(VertexTrainingTask $task, array $result): void
    {
        $this->send('run_skipped', [
            'pipeline' => $task->pipeline(),
            'status' => (string) ($result['reason'] ?? 'skipped'),
            'new_examples' => isset($result['new_examples'])
                ? $result['new_examples'].' / '.($result['required_examples'] ?? '-')
                : '-',
            'message' => (string) ($result['message'] ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function send(string $event, array $context): void
    {
        if (in_array($event, self::SILENT_EVENTS, true)) {
            return;
        }

        $emails = config('services.vertex_ai.auto_train.notification_emails', []);

        if (is_string($emails)) {
            $emails = array_values(array_filter(array_map('trim', explode(',', $emails))));
        }

        if (! is_array($emails) || $emails === []) {
            return;
        }

        try {
            Notification::route('mail', $emails)
                ->notify(new VertexTrainingRunStatusNotification($event, $context));
        } catch (\Throwable $exception) {
            Log::error('Vertex retraining notification failed', [
                'event' => $event,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
