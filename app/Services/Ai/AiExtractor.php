<?php

namespace App\Services\Ai;

interface AiExtractor
{
    /**
     * Run extraction. Takes the assembled prompt parts + document text,
     * returns normalized AiResult. Throws AiProviderException on failure
     * so a future FailoverExtractor can catch and try the next provider.
     */
    public function extract(
        string $systemPrompt,
        array $schema,
        array $outputExample,
        string $documentText,
        string $moduleDir
    ): AiResult;
}
