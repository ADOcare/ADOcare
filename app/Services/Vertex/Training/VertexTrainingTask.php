<?php

namespace App\Services\Vertex\Training;

interface VertexTrainingTask
{
    public function pipeline(): string;

    public function label(): string;

    public function location(): string;

    public function staticEndpointId(): string;

    public function datasetDirectory(): string;

    public function remoteDatasetPrefix(): string;

    public function systemInstruction(): string;

    /**
     * Load approved training examples, skipping the given feedback IDs.
     *
     * @param  array<int, int>  $excludeFeedbackIds
     * @return array<int, array{feedback_id: int, input_prompt: string, expected_output: string}>
     */
    public function loadRows(array $excludeFeedbackIds = []): array;

    /**
     * Compare a model output with the expected output of one example.
     *
     * @param  array<string, mixed>  $actual
     * @return array{schema_valid: bool, similarity: float, critical_errors: int}
     */
    public function evaluateOutput(string $expectedOutput, array $actual): array;

    public function smokePrompt(): string;

    /**
     * @param  array<string, mixed>  $decoded
     */
    public function isValidOutput(array $decoded): bool;
}
