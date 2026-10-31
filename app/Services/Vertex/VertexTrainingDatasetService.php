<?php

namespace App\Services\Vertex;

use App\Enums\VertexTrainingRunStatus;
use App\Exceptions\InsufficientTrainingDataException;
use App\Models\VertexTrainingRun;
use App\Models\VertexTrainingRunExample;
use App\Services\Vertex\Training\VertexTrainingTask;
use App\Services\Vertex\Training\VertexTrainingTasks;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VertexTrainingDatasetService
{
    private const MAX_VALIDATION_EXAMPLES = 256;

    public function __construct(private readonly VertexTrainingTasks $tasks) {}

    /**
     * Number of approved examples that were not part of a promoted or running training run yet.
     */
    public function countNewExamples(VertexTrainingTask $task): int
    {
        $trained = $this->trainedFeedbackIds($task->pipeline());

        return count(array_filter(
            $task->loadRows(),
            fn (array $row) => ! in_array($row['feedback_id'], $trained, true)
        ));
    }

    /**
     * Build the cumulative dataset (all approved examples) and upload it in Vertex tuning format.
     *
     * @return array<string, mixed>
     */
    public function buildForRun(VertexTrainingRun $run, bool $force = false): array
    {
        $task = $this->tasks->get((string) $run->pipeline);
        $version = $run->version ?: now()->format('Y-m-d-His');
        $basePath = $task->datasetDirectory();

        $this->ensureWritableDirectory($basePath);

        $validationPath = $basePath.'/validation.jsonl';
        $holdoutPath = $basePath.'/holdout.jsonl';
        [$validation, $holdout] = $this->resolveEvaluationSplit($task, $validationPath, $holdoutPath);

        $reservedIds = array_map(fn (array $row) => (int) $row['feedback_id'], array_merge($validation, $holdout));
        $training = $task->loadRows($reservedIds);

        $trained = $this->trainedFeedbackIds($task->pipeline());
        $newCount = count(array_filter($training, fn (array $row) => ! in_array($row['feedback_id'], $trained, true)));
        $minimum = (int) config('services.vertex_ai.auto_train.min_new_feedback', 25);

        if ($training === [] || (! $force && $newCount < $minimum)) {
            throw new InsufficientTrainingDataException(sprintf(
                'Nedostatok nových schválených príkladov pre retrénovanie (%d / %d).',
                $newCount,
                $minimum
            ));
        }

        $this->validateRows($training, 'training');
        $this->validateRows($validation, 'validation');

        $trainPath = $basePath.'/train-'.$version.'.jsonl';
        $this->writeRows($trainPath, $training);

        $vertexTrainPath = $basePath.'/vertex-train-'.$version.'.jsonl';
        $vertexValidationPath = $basePath.'/vertex-validation-'.$version.'.jsonl';
        $this->writeRows($vertexTrainPath, $this->toVertexRows($task, $training));
        $this->writeRows($vertexValidationPath, $this->toVertexRows($task, array_slice($validation, 0, self::MAX_VALIDATION_EXAMPLES)));

        $datasetDisk = (string) config('services.vertex_ai.auto_train.dataset_disk', 'gcs');
        $bucket = trim((string) config('filesystems.disks.'.$datasetDisk.'.bucket'));

        if ($bucket === '') {
            throw new RuntimeException("Dataset disk '{$datasetDisk}' nemá nakonfigurovaný bucket.");
        }

        $remoteTrainPath = $task->remoteDatasetPrefix().'/'.$version.'/train.jsonl';
        $remoteValidationPath = $task->remoteDatasetPrefix().'/'.$version.'/validation.jsonl';

        if (
            ! Storage::disk($datasetDisk)->put($remoteTrainPath, Storage::disk('local')->get($vertexTrainPath))
            || ! Storage::disk($datasetDisk)->put($remoteValidationPath, Storage::disk('local')->get($vertexValidationPath))
        ) {
            throw new RuntimeException("Nepodarilo sa nahrať dataset do bucketu '{$bucket}'.");
        }

        $this->recordExamples($run, 'training', $training);
        $this->recordExamples($run, 'validation', $validation);
        $this->recordExamples($run, 'holdout', $holdout);

        return [
            'version' => $version,
            'train_path' => $trainPath,
            'validation_path' => $validationPath,
            'holdout_path' => $holdoutPath,
            'training_examples_count' => count($training),
            'new_examples_count' => $newCount,
            'validation_examples_count' => count($validation),
            'holdout_examples_count' => count($holdout),
            'training_dataset_uri' => 'gs://'.$bucket.'/'.$remoteTrainPath,
            'validation_dataset_uri' => 'gs://'.$bucket.'/'.$remoteValidationPath,
            'dataset_hash' => hash('sha256', (string) Storage::disk('local')->get($vertexTrainPath)),
        ];
    }

    /**
     * Evaluation rows (validation + holdout) of the pipeline.
     *
     * @return array<int, array<string, mixed>>
     */
    public function evaluationRows(VertexTrainingTask $task): array
    {
        $basePath = $task->datasetDirectory();

        return array_values(array_filter(
            array_merge($this->readRows($basePath.'/validation.jsonl'), $this->readRows($basePath.'/holdout.jsonl')),
            fn (array $row) => $this->isCurrentFormat($row)
        ));
    }

    /**
     * Keep a stable validation/holdout split across runs; (re)seed it when missing or outdated.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function resolveEvaluationSplit(VertexTrainingTask $task, string $validationPath, string $holdoutPath): array
    {
        $validation = $this->readRows($validationPath);
        $holdout = $this->readRows($holdoutPath);
        $isCurrent = fn (array $rows) => $rows !== [] && collect($rows)->every(fn (array $row) => $this->isCurrentFormat($row));

        if ($isCurrent($validation) && $isCurrent($holdout)) {
            return [$validation, $holdout];
        }

        $validation = [];
        $holdout = [];

        foreach ($task->loadRows() as $index => $row) {
            match ($index % 10) {
                0 => $holdout[] = $row,
                1 => $validation[] = $row,
                default => null,
            };
        }

        if ($validation === [] || $holdout === []) {
            throw new InsufficientTrainingDataException('Nedostatok príkladov na vytvorenie validačnej a kontrolnej množiny.');
        }

        $this->writeRows($validationPath, $validation);
        $this->writeRows($holdoutPath, $holdout);

        return [$validation, $holdout];
    }

    /**
     * Feedback IDs already used for training by a run that is promoted or still running.
     *
     * @return array<int, int>
     */
    private function trainedFeedbackIds(string $pipeline): array
    {
        return VertexTrainingRunExample::query()
            ->where('dataset_role', 'training')
            ->whereHas('run', fn ($query) => $query
                ->where('pipeline', $pipeline)
                ->whereIn('status', [VertexTrainingRunStatus::Promoted->value, ...VertexTrainingRunStatus::activeStatuses()]))
            ->pluck('feedback_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function recordExamples(VertexTrainingRun $run, string $role, array $rows): void
    {
        foreach ($rows as $row) {
            VertexTrainingRunExample::query()->firstOrCreate([
                'training_run_id' => $run->id,
                'feedback_id' => (int) $row['feedback_id'],
                'dataset_role' => $role,
            ], ['created_at' => now()]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function toVertexRows(VertexTrainingTask $task, array $rows): array
    {
        return array_map(fn (array $row) => [
            'systemInstruction' => ['parts' => [['text' => $task->systemInstruction()]]],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => (string) $row['input_prompt']]]],
                ['role' => 'model', 'parts' => [['text' => (string) $row['expected_output']]]],
            ],
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isCurrentFormat(array $row): bool
    {
        return (int) ($row['feedback_id'] ?? 0) > 0
            && trim((string) ($row['input_prompt'] ?? '')) !== ''
            && trim((string) ($row['expected_output'] ?? '')) !== '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function validateRows(array $rows, string $role): void
    {
        if ($rows === []) {
            throw new InsufficientTrainingDataException('Dataset ('.$role.') je prázdny.');
        }

        foreach ($rows as $row) {
            if (! $this->isCurrentFormat($row)) {
                throw new RuntimeException('Dataset ('.$role.') obsahuje neplatné záznamy.');
            }
        }
    }

    private function ensureWritableDirectory(string $directory): void
    {
        $disk = Storage::disk('local');

        try {
            $created = $disk->exists($directory) || $disk->makeDirectory($directory);
        } catch (\Throwable) {
            $created = false;
        }

        $absolute = $disk->path($directory);

        if (! $created || ! is_writable($absolute)) {
            throw new RuntimeException(sprintf(
                'Priečinok %s neexistuje alebo nie je zapisovateľný pre používateľa %s. Skontrolujte oprávnenia storage/ na serveri.',
                $absolute,
                get_current_user()
            ));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readRows(string $path): array
    {
        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        $rows = [];

        foreach (preg_split('/\R/', (string) Storage::disk('local')->get($path)) ?: [] as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeRows(string $path, array $rows): void
    {
        $lines = array_map(
            fn (array $row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $rows
        );

        if (! Storage::disk('local')->put($path, implode("\n", $lines))) {
            throw new RuntimeException('Nepodarilo sa zapísať súbor '.Storage::disk('local')->path($path).'.');
        }
    }
}
