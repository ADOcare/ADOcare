<?php

namespace Tests\Unit;

use App\Services\AI\AiResponseSchemaValidator;
use PHPUnit\Framework\TestCase;

class AiResponseSchemaValidatorTest extends TestCase
{
    public function test_it_accepts_valid_nested_json(): void
    {
        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'ok' => ['type' => 'BOOLEAN'],
                'items' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ],
            'required' => ['ok', 'items'],
        ];

        (new AiResponseSchemaValidator())->assertValid($schema, ['ok' => true, 'items' => ['a', 'b']]);
        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_missing_fields(): void
    {
        $this->expectException(\RuntimeException::class);
        (new AiResponseSchemaValidator())->assertValid(
            ['type' => 'OBJECT', 'required' => ['ok']],
            []
        );
    }

    public function test_it_rejects_wrong_types(): void
    {
        $this->expectException(\RuntimeException::class);
        (new AiResponseSchemaValidator())->assertValid(
            ['type' => 'OBJECT', 'properties' => ['ok' => ['type' => 'BOOLEAN']]],
            ['ok' => 'yes']
        );
    }
}
