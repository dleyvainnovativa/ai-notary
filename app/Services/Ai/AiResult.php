<?php

namespace App\Services\Ai;

class AiResult
{
    public function __construct(
        public array $data,          // parsed field => {value, confidence}
        public int $tokensIn,
        public int $tokensOut,
        public float $costUsd,
        public string $provider,
        public string $model,
        public int $latencyMs,
    ) {}
}
