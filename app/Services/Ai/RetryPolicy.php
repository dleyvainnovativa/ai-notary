<?php

namespace App\Services\Ai;

/**
 * Retry for TRANSIENT provider failures only: timeouts, dropped connections,
 * rate limits (429) and 5xx/overloaded. Bad requests, auth errors, etc. fail at
 * once (retrying them only burns time and money). Pure PHP, testable.
 */
class RetryPolicy
{
    private const TRANSIENT = [
        'timed out', 'timeout', 'curl error 28', 'curl error 52', 'curl error 56', 'curl error 7:',
        'connection reset', 'connection refused', 'could not resolve',
        '429', 'rate limit', 'too many requests',
        '500', '502', '503', '504', 'bad gateway', 'service unavailable', 'gateway timeout',
        'overloaded', 'server error', 'temporarily',
    ];

    /** @param callable(int $seconds): void|null $sleep */
    public function __construct(
        private int $maxAttempts = 2,
        private array $backoffSeconds = [3, 8],
        private $sleep = null,
    ) {}

    public static function isTransient(\Throwable $e): bool
    {
        for ($x = $e; $x; $x = $x->getPrevious()) {
            $cls = strtolower(get_class($x));
            if (str_contains($cls, 'transporter') || str_contains($cls, 'connectexception') || str_contains($cls, 'timeout')) {
                return true;
            }
            $msg = strtolower($x->getMessage());
            foreach (self::TRANSIENT as $needle) {
                if (str_contains($msg, $needle)) return true;
            }
        }
        return false;
    }

    /**
     * @template T
     * @param callable(int $attempt): T $fn
     * @return T
     */
    public function run(callable $fn)
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $fn($attempt);
            } catch (\Throwable $e) {
                if ($attempt >= $this->maxAttempts || !self::isTransient($e)) throw $e;
                $wait = $this->backoffSeconds[$attempt - 1] ?? end($this->backoffSeconds);
                ($this->sleep ?? 'sleep')($wait);
            }
        }
    }
}
