<?php

namespace App\Services\Append;

use App\Support\ReviewData;

/**
 * Merges an appended deed (child document) into its parent's review data.
 * Pure PHP — AppendService handles locking and persistence.
 *
 * Module config (module.json → "append"):
 *   "array":        "operaciones"                       top-level array that receives the items
 *   "input":        "escritura"                         input key the deed is uploaded as
 *   "enabled_when": {"operaciones_acumuladas": "1"}     form values that enable appending (kept set after a merge)
 *   "label":        "Agregar escritura"                 button text
 */
class AppendMerger
{
    /**
     * @param array  $parentDraft  current form data of the parent (draft, or flattened AI output)
     * @param array  $parentRaw    parent's stored AI output (keyed by input, with _meta)
     * @param array  $childOutput  child's cleaned AI output (keyed by input, with _meta)
     * @return array{draft: array, raw: array, offset: int, count: int}
     */
    public function merge(array $parentDraft, array $parentRaw, array $childOutput, array $config, string $childFilename): array
    {
        $arrayKey = $config['array'] ?? null;
        $inputKey = $config['input'] ?? 'escritura';
        if (!$arrayKey) throw new AppendException('Este módulo no admite escrituras adicionales.');

        $childFlat = ReviewData::flatten($childOutput);
        $newItems = array_values(array_filter($childFlat[$arrayKey] ?? [], 'is_array'));
        if (!$newItems) {
            throw new AppendException('No se encontraron operaciones en la escritura agregada. Tu token no fue usado.');
        }

        $existing = array_values(array_filter($parentDraft[$arrayKey] ?? [], 'is_array'));
        $offset = count($existing);

        // 1. Form data: append items, keep the enabling flags set (e.g. acumuladas = Sí)
        $draft = $parentDraft;
        $draft[$arrayKey] = array_merge($existing, $newItems);
        foreach (($config['enabled_when'] ?? []) as $field => $value) {
            $draft[$field] = $value;
        }

        // 2. AI baseline (diffs): same items appended to the parent's stored output
        $raw = $parentRaw;
        $baseline = in_array($inputKey, ReviewData::FLATTENED_INPUTS, true) ? ($raw[$inputKey] ?? []) : $raw;
        $baseline[$arrayKey] = array_merge(array_values($baseline[$arrayKey] ?? []), $newItems);
        if (in_array($inputKey, ReviewData::FLATTENED_INPUTS, true)) {
            $raw[$inputKey] = $baseline;
        } else {
            $raw = $baseline + ['_meta' => $raw['_meta'] ?? []];
        }

        // 3. Notes: child's notes on its items, re-indexed; plus one note per appended item
        $notes = $parentRaw['_meta']['notes'] ?? [];
        foreach ($childOutput['_meta']['notes'] ?? [] as $n) {
            if (($n['input'] ?? '') !== $inputKey) continue;
            if (!preg_match('/^' . preg_quote($arrayKey, '/') . '\.(\d+)(\..*)?$/', $n['path'] ?? '', $m)) continue;
            $n['path'] = $arrayKey . '.' . ($offset + (int) $m[1]) . ($m[2] ?? '');
            $notes[] = $n;
        }

        $existingRfcs = $this->rfcs($existing);
        foreach ($newItems as $i => $item) {
            $path = $arrayKey . '.' . ($offset + $i);
            $notes[] = ['input' => $inputKey, 'path' => $path, 'kind' => 'append',
                'message' => 'Operación agregada desde la escritura "' . $childFilename . '". Revisa sus datos.'];

            $itemRfcs = $this->rfcs([$item]);
            if ($existingRfcs && $itemRfcs && !array_intersect($existingRfcs, $itemRfcs)) {
                $notes[] = ['input' => $inputKey, 'path' => $path, 'kind' => 'warning',
                    'message' => 'Ninguna persona de esta operación coincide (por RFC) con las operaciones anteriores. Las operaciones acumuladas deben ser del mismo cliente: verifica que la escritura sea la correcta.'];
            }
        }
        $raw['_meta'] = array_merge($raw['_meta'] ?? [], ['notes' => $notes]);

        return ['draft' => $draft, 'raw' => $raw, 'offset' => $offset, 'count' => count($newItems)];
    }

    /** All non-generic RFCs found anywhere inside the items (personas at any depth). */
    private function rfcs(array $items): array
    {
        $out = [];
        array_walk_recursive($items, function ($v, $k) use (&$out) {
            if ($k === 'rfc' && is_string($v)) {
                $r = strtoupper(trim($v));
                if ($r !== '' && !in_array($r, ['XAXX010101000', 'XEXX010101000', 'EXTF900101000', 'EXT990101000'], true)) {
                    $out[$r] = true;
                }
            }
        });
        return array_keys($out);
    }
}
