<?php

namespace App\Support;

/**
 * Shape helpers shared by the review endpoints and the append merger.
 * The AI output is stored keyed by input ('escritura', 'calculo'); the review
 * form works on a flat structure. Pure PHP.
 */
class ReviewData
{
    /** Inputs whose content is merged to the top level of the form data. */
    public const FLATTENED_INPUTS = ['escritura', 'calculo'];

    public static function flatten(?array $raw): array
    {
        if ($raw === null) return [];
        unset($raw['_meta']); // resolver notes, never form data
        if (isset($raw['escritura']) || isset($raw['calculo'])) {
            return array_merge($raw['escritura'] ?? [], $raw['calculo'] ?? []);
        }
        return $raw;
    }

    /** Map ReferenceResolver notes ({input, path}) to the same paths flatten() produces. */
    public static function notePaths(array $notes): array
    {
        return array_values(array_map(function ($n) {
            $flattened = in_array($n['input'] ?? '', self::FLATTENED_INPUTS, true);
            return [
                'path' => $flattened ? ($n['path'] ?? '') : trim(($n['input'] ?? '') . '.' . ($n['path'] ?? ''), '.'),
                'kind' => $n['kind'] ?? 'info',
                'message' => $n['message'] ?? '',
            ];
        }, $notes));
    }
}
