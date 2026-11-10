<?php

namespace App\Console\Commands;

use App\Services\AI\VertexGeminiClient;
use Illuminate\Console\Command;

class VertexAiSmokeCommand extends Command
{
    protected $signature = 'ai:vertex:smoke
        {--workflow=ocr_enhancement : One of the configured workflow names}
        {--company= : Optional company ID to attribute this test request}
        {--user= : Optional user ID to attribute this test request}';

    protected $description = 'Test the new Vertex Gemini client with synthetic non-medical data.';

    public function handle(VertexGeminiClient $client): int
    {
        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'ok' => ['type' => 'BOOLEAN'],
                'message' => ['type' => 'STRING'],
            ],
            'required' => ['ok', 'message'],
        ];

        try {
            $result = $client->generateJson(
                (string) $this->option('workflow'),
                'Return ok=true and message="Vertex test successful". This is synthetic test data.',
                'Return strictly structured JSON according to the provided schema.',
                $schema,
                $this->nullableId($this->option('company')),
                $this->nullableId($this->option('user')),
            );
        } catch (\Throwable $exception) {
            $this->error('Vertex smoke test failed: ' . $exception->getMessage());
            return self::FAILURE;
        }

        if (($result['data']['ok'] ?? false) !== true) {
            $this->error('Vertex returned a valid schema but did not confirm the smoke test.');
            return self::FAILURE;
        }

        $this->info('Vertex smoke test completed.');
        $this->line('Request ID: ' . $result['request_id']);
        $this->line('Workflow: ' . $result['workflow']);
        $this->line('Model: ' . $result['model']);
        $this->line('Response: ' . json_encode($result['data'], JSON_UNESCAPED_UNICODE));
        $this->line('Usage: ' . json_encode($result['usage'], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! ctype_digit((string) $value) || (int) $value < 1) {
            throw new \InvalidArgumentException('Company/user ID must be a positive integer.');
        }

        return (int) $value;
    }
}
