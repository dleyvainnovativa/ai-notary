<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\TokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupExpiredUploads extends Command
{
    protected $signature = 'documents:cleanup';
    protected $description = 'Delete orphaned temp upload files past their TTL (fail-safe)';

    public function handle(): int
    {
        $disk = Storage::disk(config('documents.temp_disk'));
        $dir = config('documents.temp_dir');
        $ttl = config('documents.temp_ttl_minutes');
        $deleted = 0;

        // 1. Sweep orphaned temp files — but NEVER the file of a deed still waiting in the
        //    queue (a backlog longer than the TTL used to delete it before extraction).
        //    Hard limit for privacy: after 24 h the file goes anyway and the document fails.
        $pending = [];
        Document::whereIn('status', ['uploaded', 'extracting'])->whereNotNull('inputs_json')
            ->get(['id', 'inputs_json', 'created_at'])
            ->each(function ($d) use (&$pending) {
                foreach ((array) $d->inputs_json as $meta) {
                    if (!empty($meta['temp_path'])) $pending[$meta['temp_path']] = $d;
                }
            });

        foreach ($disk->files($dir) as $file) {
            if ($disk->lastModified($file) >= now()->subMinutes($ttl)->timestamp) continue;
            $owner = $pending[$file] ?? null;
            if ($owner && $owner->created_at > now()->subHours(24)) continue;   // still queued: keep
            $disk->delete($file);
            $deleted++;
            if ($owner) {
                $doc = Document::find($owner->id);
                $doc?->update(['inputs_json' => null]);
                $doc?->markFailed('El documento no se procesó a tiempo. Tu token fue devuelto; súbelo de nuevo.');
                if ($doc?->reservation && $doc->reservation->status === 'active') {
                    app(TokenService::class)->release($doc->reservation);
                }
            }
        }

        // 2. Null temp_path on stuck documents (existing)
        Document::whereNotNull('temp_path')
            ->where('created_at', '<', now()->subMinutes($ttl))
            ->update(['temp_path' => null]);

        // 3. Purge AI output AND the review draft from completed documents older than 24h
        $purged = Document::where('status', 'completed')
            ->where(fn($q) => $q->whereNotNull('ai_output_encrypted')->orWhereNotNull('review_data_encrypted'))
            ->where('reviewed_at', '<', now()->subHours(24))
            ->update(['ai_output_encrypted' => null, 'review_data_encrypted' => null]);

        $this->info("Cleaned up {$deleted} orphaned upload(s), purged {$purged} document(s).");
        return self::SUCCESS;
    }
}
