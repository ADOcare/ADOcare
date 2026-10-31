<?php

namespace App\Services\Vertex;

use App\Models\VertexTrainingRun;
use App\Services\Vertex\Training\VertexTrainingTask;
use App\Services\Vertex\Training\VertexTrainingTasks;

class VertexCandidateEvaluationService
{
    public function __construct(
        private readonly VertexTrainingTasks $tasks,
        private readonly VertexTrainingDatasetService $datasets,
        private readonly VertexInferenceClient $inferenceClient,
        private readonly VertexAutotrainStateService $stateService
    ) {}

    /**
     * Compare the candidate endpoint with the current production endpoint on validation + holdout rows.
     *
     * @return array<string, mixed>
     */
    public function evaluate(VertexTrainingRun $run): array
    {
        $task = $this->tasks->get((string) $run->pipeline);
        $rows = array_slice(
            $this->datasets->evaluationRows($task),
            0,
            max(1, (int) config('services.vertex_ai.auto_train.max_evaluation_examples', 50))
        );

        if ($rows === []) {
            throw new \RuntimeException('Chýbajú validačné dáta pre vyhodnotenie kandidáta.');
        }

        $state = $this->stateService->read($task->pipeline());
        $currentLocation = trim((string) ($state['active_location'] ?? $task->location()));
        $currentEndpointId = trim((string) ($state['active_endpoint_id'] ?? $task->staticEndpointId()));

        if ($currentLocation === '' || $currentEndpointId === '') {
            throw new \RuntimeException('Nie je dostupný aktuálny produkčný endpoint na porovnanie.');
        }

        $candidateMetrics = $this->runMetrics($task, $rows, (string) $run->new_location, (string) $run->new_endpoint_id);
        $currentMetrics = $this->runMetrics($task, $rows, $currentLocation, $currentEndpointId);

        $candidateScore = $this->computeScore($candidateMetrics);
        $currentScore = $this->computeScore($currentMetrics);
        $maxRegression = (float) config('services.vertex_ai.auto_train.max_score_regression', 0.01);

        return [
            'candidate' => $candidateMetrics,
            'current' => $currentMetrics,
            'candidate_score' => $candidateScore,
            'current_score' => $currentScore,
            'score_regression' => $currentScore - $candidateScore,
            'passes' => $this->passesThresholds($candidateMetrics, $currentMetrics, $candidateScore, $currentScore, $maxRegression),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function runMetrics(VertexTrainingTask $task, array $rows, string $location, string $endpointId): array
    {
        $validJson = 0;
        $requiredSchema = 0;
        $emptyOutputs = 0;
        $httpFailures = 0;
        $criticalErrors = 0;
        $latencySum = 0.0;
        $similaritySum = 0.0;

        foreach ($rows as $row) {
            $start = microtime(true);

            try {
                $response = $this->inferenceClient->invokeEndpoint($location, $endpointId, (string) $row['input_prompt'], $task->systemInstruction());
                $latencySum += (microtime(true) - $start) * 1000;
            } catch (\Throwable) {
                $httpFailures++;

                continue;
            }

            $text = trim((string) data_get($response, 'candidates.0.content.parts.0.text', ''));

            if ($text === '') {
                $emptyOutputs++;

                continue;
            }

            if (str_contains($text, '```')) {
                $criticalErrors++;
            }

            $decoded = json_decode($text, true);

            if (! is_array($decoded)) {
                continue;
            }

            $validJson++;
            $result = $task->evaluateOutput((string) $row['expected_output'], $decoded);

            if (! $result['schema_valid']) {
                continue;
            }

            $requiredSchema++;
            $similaritySum += $result['similarity'];
            $criticalErrors += $result['critical_errors'];
        }

        $total = max(count($rows), 1);

        return [
            'total' => count($rows),
            'valid_json_rate' => $validJson / $total,
            'required_fields_rate' => $requiredSchema / $total,
            'empty_output_rate' => $emptyOutputs / $total,
            'http_failures' => $httpFailures,
            'average_latency_ms' => $latencySum / $total,
            'similarity' => $similaritySum / $total,
            'critical_error_count' => $criticalErrors,
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function computeScore(array $metrics): float
    {
        $score = ((float) ($metrics['valid_json_rate'] ?? 0.0)) * 0.30
            + ((float) ($metrics['required_fields_rate'] ?? 0.0)) * 0.30
            + ((float) ($metrics['similarity'] ?? 0.0)) * 0.40;

        $score -= min(0.50, ((int) ($metrics['critical_error_count'] ?? 0)) * 0.02);
        $score -= min(0.20, ((int) ($metrics['http_failures'] ?? 0)) * 0.01);

        return max(0.0, min(1.0, $score));
    }

    /**
     * The candidate may not produce more critical errors than the current model (or the configured maximum).
     *
     * @param  array<string, mixed>  $candidateMetrics
     * @param  array<string, mixed>  $currentMetrics
     */
    private function passesThresholds(array $candidateMetrics, array $currentMetrics, float $candidateScore, float $currentScore, float $maxRegression): bool
    {
        $minJson = (float) config('services.vertex_ai.auto_train.min_json_validity', 1.00);
        $minRequired = (float) config('services.vertex_ai.auto_train.min_required_fields_rate', 1.00);
        $maxHttpFailures = (int) config('services.vertex_ai.auto_train.max_http_failures', 0);
        $maxCritical = max(
            (int) config('services.vertex_ai.auto_train.max_critical_errors', 0),
            (int) ($currentMetrics['critical_error_count'] ?? 0)
        );

        return (float) ($candidateMetrics['valid_json_rate'] ?? 0.0) >= $minJson
            && (float) ($candidateMetrics['required_fields_rate'] ?? 0.0) >= $minRequired
            && (int) ($candidateMetrics['http_failures'] ?? 0) <= $maxHttpFailures
            && (int) ($candidateMetrics['critical_error_count'] ?? 0) <= $maxCritical
            && ($currentScore - $candidateScore) <= $maxRegression;
    }
}
