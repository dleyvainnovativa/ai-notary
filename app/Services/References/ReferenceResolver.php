<?php

namespace App\Services\References;

/**
 * Resolves in-deed references the AI could not (or should not) resolve itself:
 *
 *  1. Dates by reference to the deed ("en esta fecha", "en este acto"...):
 *     the prompt asks the AI to return the sentinel "@FECHA_ESCRITURA"; any date
 *     field still holding the sentinel OR a literal reference phrase is filled
 *     with the module's deed date. Works across inputs (declaranot's "calculo"
 *     never sees the deed text, so only this step can fill it).
 *
 *  2. Addresses by reference ("con mismo domicilio que el anterior"):
 *     the AI copies the address (it is the only one that sees text order) and
 *     reports the source in "copiado_de". This step turns that marker into a
 *     review note, and as a safety net handles a literal phrase left in an
 *     address field by copying the previous person's address in the same array.
 *
 * Every value it fills produces a note so the review form can ask the user to
 * verify it. Pure PHP (no framework calls) so it can be tested standalone.
 *
 * Module config (module.json → "references"):
 *   "deed_date":    ["escritura.fecha_firma_escritura"]          path(s) with * for array indexes
 *   "address_keys": ["domicilio"]                                  optional, default ["domicilio"]
 */
class ReferenceResolver
{
    public const DATE_SENTINEL = '@FECHA_ESCRITURA';

    private const DATE_PHRASE = '/\b(en\s+esta\s+fecha|en\s+este\s+acto|a\s+la\s+firma|el\s+d[ií]a\s+de\s+hoy|en\s+la\s+fecha\s+de\s+(firma|otorgamiento)|fecha\s+de\s+(la\s+)?escritura)\b/iu';
    private const ADDRESS_PHRASE = '/\b(mism[oa]|igual)\s+domicilio\b|\bdomicilio\s+(ya\s+|antes\s+)?(se[ñn]alado|citado|mencionado|indicado)\b/iu';

    private array $data;
    private array $notes;
    private array $datePatterns;
    private array $deedDatePaths;
    private array $addressKeys;

    /**
     * @param array $merged          AI output keyed by input key: ['escritura' => [...], 'calculo' => [...]]
     * @param array $schemasByInput  extraction schema per input key (decoded *_schema.json)
     * @param array $config          module.json "references" block
     */
    public function resolve(array $merged, array $schemasByInput, array $config): ResolverResult
    {
        $this->data = $merged;
        $this->notes = [];
        $this->deedDatePaths = (array) ($config['deed_date'] ?? []);
        $this->addressKeys = (array) ($config['address_keys'] ?? ['domicilio']);

        $this->datePatterns = [];
        foreach ($schemasByInput as $inputKey => $schema) {
            $this->collectDatePatterns($schema['fields'] ?? [], (string) $inputKey);
        }

        // Addresses first: copying an address never touches date fields.
        $this->walkAddresses($this->data, []);
        $this->walkDates($this->data, []);

        return new ResolverResult($this->data, $this->notes);
    }

    /* ------------------------------------------------------------------ */
    /* Dates                                                              */
    /* ------------------------------------------------------------------ */

    private function collectDatePatterns(array $fields, string $prefix): void
    {
        foreach ($fields as $name => $def) {
            if (!is_array($def)) continue;
            $type = $def['type'] ?? null;
            if ($type === 'date') {
                $this->datePatterns["{$prefix}.{$name}"] = true;
            } elseif ($type === 'array' && is_array($def['items'] ?? null)) {
                $this->collectDatePatterns($def['items'], "{$prefix}.{$name}.*");
            } elseif ($type === 'object' && is_array($def['items'] ?? null)) {
                $this->collectDatePatterns($def['items'], "{$prefix}.{$name}");
            }
        }
    }

    private function walkDates(array &$node, array $path): void
    {
        foreach ($node as $key => &$value) {
            $p = [...$path, (string) $key];
            if (is_array($value)) {
                $this->walkDates($value, $p);
                continue;
            }
            if (!is_string($value) || trim($value) === '') continue;

            $isSentinel = str_contains($value, self::DATE_SENTINEL);
            $isDateField = isset($this->datePatterns[$this->pattern($p)]);
            $isPhrase = $isDateField && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
                && preg_match(self::DATE_PHRASE, $value);

            if (!$isSentinel && !$isPhrase) continue;

            $deedDate = $this->deedDateFor($p);
            $value = $deedDate; // null when unresolvable

            $this->notes[] = $deedDate
                ? $this->note($p, 'deed_date', 'Fecha tomada de la fecha de la escritura (' . $this->human($deedDate) . '): el documento la indica como "en esta fecha" o similar. Verifícala.')
                : $this->note($p, 'deed_date', 'El documento indica esta fecha por referencia a la escritura, pero no se encontró la fecha de la escritura. Captúrala manualmente.');
        }
        unset($value);
    }

    /** Materialize each deed_date pattern using the indexes of the field being resolved. */
    private function deedDateFor(array $fieldPath): ?string
    {
        foreach ($this->deedDatePaths as $pattern) {
            $segments = explode('.', $pattern);
            $concrete = [];
            foreach ($segments as $i => $seg) {
                if ($seg === '*') {
                    $samePrefix = array_slice($fieldPath, 0, $i) === $concrete;
                    $seg = ($samePrefix && isset($fieldPath[$i]) && ctype_digit($fieldPath[$i])) ? $fieldPath[$i] : '0';
                }
                $concrete[] = $seg;
            }
            if ($concrete === $fieldPath) continue; // never resolve the deed date from itself

            $val = $this->get($this->data, $concrete);
            if (is_string($val) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                return $val;
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Addresses                                                          */
    /* ------------------------------------------------------------------ */

    private function walkAddresses(array &$node, array $path): void
    {
        foreach ($node as $key => &$value) {
            if (!is_array($value)) continue;
            $p = [...$path, (string) $key];

            if (in_array((string) $key, $this->addressKeys, true) && !array_is_list($value)) {
                $this->resolveAddress($value, $p);
                continue;
            }
            $this->walkAddresses($value, $p);
        }
        unset($value);
    }

    private function resolveAddress(array &$addr, array $p): void
    {
        $copiedFrom = $addr['copiado_de'] ?? null;
        unset($addr['copiado_de']); // never reaches the form / exporter

        // A. The AI resolved it and told us where it came from.
        if (is_string($copiedFrom) && trim($copiedFrom) !== '') {
            $hasData = $this->hasAddressData($addr);
            $this->notes[] = $hasData
                ? $this->note($p, 'address', 'Domicilio tomado de: ' . trim($copiedFrom) . ' (la escritura dice "mismo domicilio que el anterior" o similar). Verifícalo.')
                : $this->note($p, 'address', 'La escritura refiere el domicilio a otra persona ("' . trim($copiedFrom) . '") pero no se pudo identificar. Captúralo manualmente.');
            return;
        }

        // B. Safety net: the literal phrase ended up inside an address field.
        $phraseFound = false;
        foreach ($addr as $v) {
            if (is_string($v) && preg_match(self::ADDRESS_PHRASE, $v)) {
                $phraseFound = true;
                break;
            }
        }
        if (!$phraseFound) return;

        $source = $this->previousSiblingAddress($p);
        if ($source !== null) {
            unset($source['copiado_de']);
            $addr = $source;
            $this->notes[] = $this->note($p, 'address', 'Domicilio copiado de la persona anterior (la escritura dice "mismo domicilio que el anterior"). Verifica que sea la persona correcta.');
        } else {
            foreach ($addr as $k => $v) {
                if (is_string($v) && preg_match(self::ADDRESS_PHRASE, $v)) $addr[$k] = null;
            }
            $this->notes[] = $this->note($p, 'address', 'La escritura refiere el domicilio a otra persona y no se pudo resolver. Captúralo manualmente.');
        }
    }

    /** For ...{array}.{N}.domicilio → ...{array}.{N-1}.domicilio, if it holds real data. */
    private function previousSiblingAddress(array $p): ?array
    {
        $n = count($p);
        if ($n < 2 || !ctype_digit($p[$n - 2]) || (int) $p[$n - 2] === 0) return null;

        $prev = $p;
        $prev[$n - 2] = (string) ((int) $p[$n - 2] - 1);
        $addr = $this->get($this->data, $prev);

        if (!is_array($addr) || !$this->hasAddressData($addr)) return null;
        foreach ($addr as $v) {
            if (is_string($v) && preg_match(self::ADDRESS_PHRASE, $v)) return null;
        }
        return $addr;
    }

    private function hasAddressData(array $addr): bool
    {
        foreach (['calle', 'codigo_postal', 'colonia', 'municipio'] as $k) {
            if (isset($addr[$k]) && trim((string) $addr[$k]) !== '') return true;
        }
        return false;
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function get(array $data, array $path): mixed
    {
        foreach ($path as $seg) {
            if (!is_array($data) || !array_key_exists($seg, $data)) return null;
            $data = $data[$seg];
        }
        return $data;
    }

    private function pattern(array $path): string
    {
        return implode('.', array_map(fn($s) => ctype_digit($s) ? '*' : $s, $path));
    }

    /** Note path is reported WITHOUT the input key; the input is kept separately. */
    private function note(array $path, string $kind, string $message): array
    {
        return [
            'input' => $path[0] ?? '',
            'path' => implode('.', array_slice($path, 1)),
            'kind' => $kind,
            'message' => $message,
        ];
    }

    private function human(string $ymd): string
    {
        [$y, $m, $d] = explode('-', $ymd);
        return "{$d}/{$m}/{$y}";
    }
}
