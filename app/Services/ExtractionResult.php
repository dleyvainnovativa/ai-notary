<?php

namespace App\Services;

class ExtractionResult
{
    public function __construct(
        public string $text,
        public ?int $pageCount,
        public bool $scanned,
    ) {}

    public function isEmpty(): bool
    {
        return mb_strlen(trim($this->text)) < 20;
    }
}
