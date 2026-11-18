<?php

namespace App\Services\Ai;

use App\Services\ExtractionResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Extracts text from legacy Word files (.doc, application/msword) via the OpenAI
 * Responses API `input_file` flow.
 *
 * PhpWord cannot read the old binary .doc format, and antiword / LibreOffice are
 * not available on shared hosting, so OpenAI performs the extraction server-side
 * (for non-PDF documents the Responses API "extracts text only"). The returned
 * text then feeds the same pipeline as PDF / .docx. Old-format extraction quality
 * varies, which is why the upload UI warns the user. On any failure this returns
 * an empty result so the caller fails gracefully and releases the token.
 */
class DocTextExtractor
{
    public function extract(string $absolutePath): ExtractionResult
    {
        $base  = rtrim(config('openai.base_uri') ?: 'https://api.openai.com/v1', '/');
        $model = config('ai.openai.model', 'gpt-4o-mini');
        $timeout = (int) config('openai.request_timeout', 90);

        $client = fn() => Http::withToken(config('openai.api_key'))
            ->when(config('openai.organization'), fn($h) => $h->withHeaders(['OpenAI-Organization' => config('openai.organization')]))
            ->when(config('openai.project'), fn($h) => $h->withHeaders(['OpenAI-Project' => config('openai.project')]))
            ->timeout($timeout);

        $fileId = null;
        try {
            // 1) Upload the .doc so the model can read it (purpose: user_data).
            $uploaded = $client()
                ->attach('file', file_get_contents($absolutePath), 'document.doc')
                ->post("$base/files", ['purpose' => 'user_data'])
                ->throw()->json();
            $fileId = $uploaded['id'] ?? null;
            if (!$fileId) {
                throw new \RuntimeException('OpenAI Files API returned no file id');
            }

            // 2) Responses API extracts the text from the uploaded document.
            $response = $client()->post("$base/responses", [
                'model' => $model,
                'input' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_file', 'file_id' => $fileId],
                        ['type' => 'input_text', 'text' =>
                            'Devuelve ÚNICAMENTE el texto completo de este documento, respetando los saltos de línea. '
                            . 'No agregues comentarios, encabezados ni explicaciones.'],
                    ],
                ]],
            ])->throw()->json();

            $text = trim($this->outputText($response));
            return new ExtractionResult($text, null, scanned: $text === '');
        } catch (\Throwable $e) {
            Log::warning('DOC extraction via OpenAI failed', ['error' => $e->getMessage()]);
            return new ExtractionResult('', null, scanned: true);
        } finally {
            if ($fileId) {
                try { $client()->delete("$base/files/{$fileId}"); } catch (\Throwable) {}
            }
        }
    }

    /** Text from a Responses payload: the output_text convenience field, else walk output[].content[]. */
    private function outputText(array $response): string
    {
        if (!empty($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }
        $out = '';
        foreach ($response['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'output_text' && isset($c['text'])) {
                    $out .= $c['text'];
                }
            }
        }
        return $out;
    }
}
