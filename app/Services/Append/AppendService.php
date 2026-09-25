<?php

namespace App\Services\Append;

use App\Models\Document;
use App\Modules\ModuleRegistry;
use App\Support\ReviewData;
use Illuminate\Support\Facades\DB;

/**
 * Persists an appended deed into its parent under a row lock. Called by
 * ProcessDocumentJob for child documents, BEFORE the child's token is consumed
 * (a failed merge releases the token).
 */
class AppendService
{
    public function __construct(private ModuleRegistry $registry, private AppendMerger $merger) {}

    /** @return array{offset: int, count: int} */
    public function mergeIntoParent(Document $child, array $childOutput): array
    {
        return DB::transaction(function () use ($child, $childOutput) {
            $parent = Document::whereKey($child->parent_document_id)->lockForUpdate()->first();

            if (!$parent || $parent->user_id !== $child->user_id) {
                throw new AppendException('No se encontró la escritura principal. Tu token no fue usado.');
            }
            if ($parent->status !== 'requires_review') {
                throw new AppendException('La escritura principal ya no está en revisión (¿ya se exportó?). Tu token no fue usado.');
            }

            $config = $this->registry->manifest($parent->module_slug)['append'] ?? null;
            if (!$config) throw new AppendException('Este módulo no admite escrituras adicionales.');

            $parentRaw = json_decode($parent->ai_output_encrypted ?? '', true) ?: [];
            $parentDraft = $parent->review_data_encrypted ?? ReviewData::flatten($parentRaw);

            $result = $this->merger->merge($parentDraft, $parentRaw, $childOutput, $config, $child->original_filename);

            $parent->forceFill(['ai_output_encrypted' => json_encode($result['raw'])]);
            $parent->saveDraft($result['draft']);   // saves both fields, bumps review_version

            return ['offset' => $result['offset'], 'count' => $result['count']];
        });
    }
}
