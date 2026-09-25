<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Modules\ModuleRegistry;
use App\Services\TokenService;

class DashboardController extends Controller
{
    private const RECENT_LIMIT = 15;

    public function index(TokenService $tokens, ModuleRegistry $registry)
    {
        $user = auth()->user();
        $modules = $registry->active();

        // Only NULL-ness of the encrypted columns is needed (can the review still open?),
        // so never load/decrypt their contents here.
        $recent = Document::where('user_id', $user->id)
            ->select(['id', 'module_slug', 'original_filename', 'status', 'last_error', 'updated_at', 'review_saved_at'])
            ->selectRaw('(review_data_encrypted IS NOT NULL OR ai_output_encrypted IS NOT NULL) as has_data')
            ->latest('updated_at')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn($d) => [
                'id' => $d->id,
                'filename' => $d->original_filename,
                'module' => $modules[$d->module_slug]['name'] ?? $d->module_slug,
                'status' => $d->status,
                'error' => $d->last_error,
                'updated_at' => $d->updated_at,
                'has_draft' => $d->review_saved_at !== null,
                'can_open' => in_array($d->status, Document::REVIEWABLE, true) && (bool) $d->has_data,
                'in_progress' => in_array($d->status, ['uploaded', 'extracting', 'processing'], true),
            ]);

        return view('dashboard', [
            'balance' => $tokens->balance($user),
            'processedCount' => Document::where('user_id', $user->id)
                ->whereIn('status', ['requires_review', 'completed'])->count(),
            'moduleCount' => count($modules),
            'recent' => $recent,
        ]);
    }
}
