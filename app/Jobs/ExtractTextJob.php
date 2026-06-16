<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\TextExtractor;
use App\Services\TokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ExtractTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $documentId) {}

    public function handle(TextExtractor $extractor, TokenService $tokens): void
    {
        $document = Document::find($this->documentId);
        if (!$document || empty($document->inputs_json)) return;

        $document->update(['status' => 'extracting']);
        $disk = Storage::disk(config('documents.temp_disk'));
        $extracted = [];

        try {
            foreach ($document->inputs_json as $key => $meta) {
                $path = $disk->path($meta['temp_path']);
                $result = $extractor->extract($path, $meta['mime']);

                if ($result->isEmpty()) {
                    $this->failGracefully(
                        $document,
                        $tokens,
                        "Could not read the '{$key}' file (it may be scanned). OCR support is coming soon."
                    );
                    return;
                }
                $extracted[$key] = $result->text;
            }

            $document->update(['status' => 'processing']);
            \App\Jobs\ProcessDocumentJob::dispatch($document->id, $extracted); // map of key=>text

        } catch (\Throwable $e) {
            if ($this->attempts() >= $this->tries) {
                $this->failGracefully($document, $tokens, 'Could not extract text from the uploaded files.');
                return;
            }
            throw $e;
        } finally {
            $this->deleteAllFiles($document);
        }
    }

    private function deleteAllFiles(Document $document): void
    {
        $disk = Storage::disk(config('documents.temp_disk'));
        foreach (($document->inputs_json ?? []) as $meta) {
            if (!empty($meta['temp_path']) && $disk->exists($meta['temp_path'])) {
                $disk->delete($meta['temp_path']);
            }
        }
        $document->update(['inputs_json' => null]);
    }

    private function failGracefully(Document $document, TokenService $tokens, string $reason): void
    {
        $document->markFailed($reason);
        if ($document->reservation) {
            $tokens->release($document->reservation); // give the token back
        }
    }

    private function deleteFile(Document $document): void
    {
        if ($document->temp_path && Storage::disk(config('documents.temp_disk'))->exists($document->temp_path)) {
            Storage::disk(config('documents.temp_disk'))->delete($document->temp_path);
        }
        $document->update(['temp_path' => null]); // record that the file is gone
    }

    /** If the job is permanently failing, the queue calls this. */
    public function failed(\Throwable $e): void
    {
        $document = Document::find($this->documentId);
        if (!$document) return;

        $this->deleteFile($document);

        // Only override status if it's not already a terminal state
        if (!in_array($document->status, ['completed', 'requires_review', 'failed'])) {
            $document->markFailed('Extraction failed permanently.');
        }
        if ($document->reservation && $document->reservation->status === 'active') {
            app(TokenService::class)->release($document->reservation);
        }
    }
}
