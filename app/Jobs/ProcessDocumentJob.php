<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\ProcessingCost;
use App\Modules\ModuleRegistry;
use App\Services\Ai\AiExtractor;
use App\Services\References\ReferenceResolver;
use App\Services\TokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    // The extracted text arrives here in the payload — not from any store.
    public function __construct(public int $documentId, public array $extractedTextByInput) {}

    public function handle(ModuleRegistry $registry, AiExtractor $extractor, TokenService $tokens, ReferenceResolver $resolver): void
    {
        $document = Document::find($this->documentId);
        if (!$document) return;
        $document->update(['status' => 'processing']);

        try {
            $module = $registry->controller($document->module_slug);
            $manifest = $registry->manifest($document->module_slug);
            $dir = $registry->moduleDir($document->module_slug); // expose this in registry

            $merged = [];
            $schemasByInput = [];
            foreach ($module->inputs() as $input) {
                if (!isset($this->extractedTextByInput[$input->key])) continue; // optional & absent

                $schemasByInput[$input->key] = $input->schema($dir);

                $result = $extractor->extract(
                    $input->prompt($dir),
                    $schemasByInput[$input->key],
                    $input->outputExample($dir),
                    $this->extractedTextByInput[$input->key],
                    $dir   // ← add this
                );

                ProcessingCost::create([
                    'document_id' => $document->id,
                    'user_id' => $document->user_id,
                    'module_slug' => $document->module_slug,
                    'provider' => $result->provider,
                    'model' => $result->model,
                    'tokens_in' => $result->tokensIn,
                    'tokens_out' => $result->tokensOut,
                    'cost_usd' => $result->costUsd,
                    'latency_ms' => $result->latencyMs,
                ]);

                // Merge under the input key so the review form knows which section is which
                $merged[$input->key] = $result->data;
            }

            // Core step (all modules): resolve "en esta fecha" / "mismo domicilio que el anterior".
            $resolved = $resolver->resolve($merged, $schemasByInput, $manifest['references'] ?? []);

            $cleaned = $module->postProcess($resolved->data);
            $cleaned['_meta'] = ['notes' => $resolved->notes];

            $document->update([
                'module_version' => $manifest['version'],
                'ai_output_encrypted' => json_encode($cleaned),
                'status' => 'requires_review',
            ]);

            if ($document->reservation && $document->reservation->status === 'active') {
                $tokens->consume($document->reservation);
            }
        } catch (\App\Services\Ai\AiProviderException $e) {
            if ($this->attempts() >= $this->tries) {
                $this->failGracefully($document, $tokens, 'AI processing failed. Your token was not used.');
                return;
            }
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Error processing document ID ' . $document->id . ': ' . $e->getMessage());
            $this->failGracefully($document, $tokens, 'Processing error.');
            return;
        }
    }
    private function failGracefully(Document $document, TokenService $tokens, string $reason): void
    {
        $document->markFailed($reason);
        if ($document->reservation && $document->reservation->status === 'active') {
            $tokens->release($document->reservation);
        }
    }
}
