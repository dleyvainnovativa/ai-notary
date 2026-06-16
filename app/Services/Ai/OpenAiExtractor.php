<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class OpenAiExtractor implements AiExtractor
{
    public function extract(
        string $systemPrompt,
        array $schema,
        array $outputExample,
        string $documentText,
        string $moduleDir
    ): AiResult {
        $model = config('ai.openai.model');
        $started = microtime(true);

        $resolvedSchema = app(\App\Services\CatalogService::class)->inject($schema, $moduleDir);

        $userPrompt = "SCHEMA:\n" . json_encode($resolvedSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            . "\n\nEXPECTED OUTPUT EXAMPLE:\n" . json_encode($outputExample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            . "\n\nDOCUMENT:\n" . $documentText;

        try {
            $response = OpenAI::chat()->create([
                'model' => $model,
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);
        } catch (\Throwable $e) {
            throw new AiProviderException('OpenAI request failed: ' . $e->getMessage(), 0, $e);
        }

        $content = trim($response->choices[0]->message->content ?? '');
        $content = trim(str_replace(['```json', '```'], '', $content));
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AiProviderException('Invalid JSON from AI: ' . json_last_error_msg());
        }

        $usage = $response->usage;
        $tokensIn = $usage->promptTokens ?? 0;
        $tokensOut = $usage->completionTokens ?? 0;

        return new AiResult(
            data: $data,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            costUsd: $this->cost($tokensIn, $tokensOut, $model),
            provider: 'openai',
            model: $model,
            latencyMs: (int) ((microtime(true) - $started) * 1000),
        );
    }

    private function buildUserMessage(array $outputExample, string $documentText): string
    {
        return "EXAMPLE OUTPUT (format reference only):\n"
            . json_encode($outputExample, JSON_PRETTY_PRINT) . "\n\n"
            . "DOCUMENT TEXT:\n" . $documentText;
    }

    /**
     * Wrap the module's field definitions into a strict JSON schema where
     * every field returns {value, confidence}. This is what guarantees shape.
     */
    /** Recursively build a strict JSON schema that wraps every leaf in {value, confidence}. */
    private function buildResponseSchema(array $moduleSchema): array
    {
        $fields = $moduleSchema['fields'] ?? $moduleSchema;
        return $this->wrapNode(['type' => 'object', 'items' => $fields]);
    }

    private function wrapNode(array $def): array
    {
        $type = $def['type'] ?? 'text';

        if ($type === 'array') {
            return [
                'type' => 'array',
                'items' => $this->wrapNode(['type' => 'object', 'items' => $def['items']]),
            ];
        }

        if ($type === 'object') {
            $props = [];
            $required = [];
            foreach ($def['items'] as $name => $child) {
                $props[$name] = $this->wrapNode($child);
                $required[] = $name;
            }
            return [
                'type' => 'object',
                'properties' => $props,
                'required' => $required,
                'additionalProperties' => false,
            ];
        }

        // Leaf field → {value, confidence}
        return [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => ['string', 'number', 'null']],
                'confidence' => ['type' => 'number'],
            ],
            'required' => ['value', 'confidence'],
            'additionalProperties' => false,
        ];
    }

    private function cost(int $in, int $out, string $model): float
    {
        $rates = config("ai.openai.pricing.{$model}", ['in' => 0, 'out' => 0]);
        return round(($in / 1_000_000) * $rates['in'] + ($out / 1_000_000) * $rates['out'], 6);
    }
}
