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

    /** Rules whose errors stop the export (the rest only inform the reviewer). */
    public const BLOCKING_RULES = ['sum_equals', 'less_than', 'rfc_persona'];

    /** @return ValidationIssue[] */
    public function blockingIssues(): array
    {
        return array_values(array_filter($this->issues, fn($i) => in_array($i->rule, self::BLOCKING_RULES, true)));
    }

    public function isValid(): bool
    {
        return empty($this->issues);
    }
}
