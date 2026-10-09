<?php

namespace App\Services\Claims;

final readonly class ResolvedInsured
{
    public function __construct(
        public string $character,
        public string $category,
        public string $identificationMethod,
        public ?string $personalNumber,
        public ?string $memberStateCode,
        public ?string $foreignInsuredId,
        public ?string $sex,
        public ?string $specialCategory,
        public array $errors,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
