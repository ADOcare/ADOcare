<?php

namespace App\Services\AI;

/**
 * A small safety check for the subset of Vertex responseSchema used here.
 * Not a general JSON Schema implementation. Add a proper validator if needed.
 */
class AiResponseSchemaValidator
{
    /** @param array<string, mixed> $schema */
    public function assertValid(array $schema, mixed $data): void
    {
        $this->check($schema, $data, 0);
    }

    /** @param array<string, mixed> $schema */
    private function check(array $schema, mixed $data, int $depth): void
    {
        if ($depth > 12) {
            throw new \RuntimeException('AI JSON schema exceeds maximum validation depth.');
        }

        if ($data === null && ($schema['nullable'] ?? false) === true) {
            return;
        }

        $type = strtoupper((string) ($schema['type'] ?? 'OBJECT'));
        $valid = match ($type) {
            'OBJECT' => is_array($data) && (! array_is_list($data) || $data === []),
            'ARRAY' => is_array($data) && array_is_list($data),
            'STRING' => is_string($data),
            'INTEGER' => is_int($data),
            'NUMBER' => is_int($data) || is_float($data),
            'BOOLEAN' => is_bool($data),
            default => false,
        };

        if (! $valid) {
            throw new \RuntimeException('Vertex returned JSON incompatible with the declared response schema.');
        }

        if (isset($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            throw new \RuntimeException('Vertex returned an unexpected enumerated value.');
        }

        if ($type === 'OBJECT') {
            foreach (($schema['required'] ?? []) as $field) {
                if (! array_key_exists($field, $data)) {
                    throw new \RuntimeException('Vertex JSON response is missing a required field.');
                }
            }

            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_keys($data) as $field) {
                    if (! array_key_exists($field, $schema['properties'] ?? [])) {
                        throw new \RuntimeException('Vertex JSON response contains an unexpected field.');
                    }
                }
            }

            foreach (($schema['properties'] ?? []) as $field => $propertySchema) {
                if (array_key_exists($field, $data) && is_array($propertySchema)) {
                    $this->check($propertySchema, $data[$field], $depth + 1);
                }
            }
        }

        if ($type === 'ARRAY' && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($data as $value) {
                $this->check($schema['items'], $value, $depth + 1);
            }
        }
    }
}
