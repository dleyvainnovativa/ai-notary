<?php

namespace App\Console\Commands;

use App\Models\Document;
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

        // 1. Sweep orphaned temp files (existing)
        foreach ($disk->files($dir) as $file) {
            if ($disk->lastModified($file) < now()->subMinutes($ttl)->timestamp) {
                $disk->delete($file);
                $deleted++;
            }
        }

        // 2. Null temp_path on stuck documents (existing)
        Document::whereNotNull('temp_path')
            ->where('created_at', '<', now()->subMinutes($ttl))
            ->update(['temp_path' => null]);

        // 3. NEW: purge AI output from completed documents older than 24h
        $purged = Document::where('status', 'completed')
            ->whereNotNull('ai_output_encrypted')
            ->where('reviewed_at', '<', now()->subHours(24))
            ->update(['ai_output_encrypted' => null]);

        $this->info("Cleaned up {$deleted} orphaned upload(s), purged {$purged} document(s).");
        return self::SUCCESS;
    }
}
