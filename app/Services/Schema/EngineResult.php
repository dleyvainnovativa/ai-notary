<?php

namespace App\Services\Schema;

class EngineResult
{
    /**
     * @param array $data    The processed data (computed/derived/rules applied)
     * @param ValidationIssue[] $issues
     */
    public function __construct(
        public array $data,
        public array $issues,
    ) {}

    public function isValid(): bool
    {
        return empty($this->issues);
    }
}
