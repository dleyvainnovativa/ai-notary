<?php

namespace App\Services\References;

class ResolverResult
{
    public function __construct(
        public array $data,   // merged AI output with references resolved
        public array $notes,  // [{input, path, kind, message}] — info for the review form
    ) {}
}
