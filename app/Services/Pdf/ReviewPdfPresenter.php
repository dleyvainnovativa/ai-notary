<?php

namespace App\Services\Pdf;

use App\Services\Schema\SchemaEngine;

/**
 * Turns ANY module's formSchema + review data into a print view model, so every
 * module (current and future) gets a PDF without its own template.
 *
 *  sections → blocks, where a block is one of:
 *    grid   leaf fields as label/value cells
 *    group  an object (e.g. Domicilio) with its own blocks
 *    cards  an array of complex rows (Adquirentes, Operaciones…), one card per row
 *    table  an array whose rows are all leaves (Pagos) — with totals for money columns
 *    empty  an array with no rows
 *
 * Visibility follows the review form exactly (SchemaEngine::isVisible), so fields
 * hidden for a persona case never print. Optional formSchema hints:
 *   'item_label' => 'Adquirente'  (array)  card/row title
 *   'money'      => true          (number) prints as $1,234.56 and totals in tables
 */
class ReviewPdfPresenter
{
    private array $notes = [];

    public function __construct(private SchemaEngine $engine) {}

    /**
     * @param array $notes [{path, message}] from the ReferenceResolver (already mapped to form paths)
     */
    public function present(array $formSchema, array $data, array $notes = []): array
    {
        $this->notes = [];
        foreach ($notes as $n) {
            if (!empty($n['path']) && !empty($n['message'])) $this->notes[$n['path']][] = $n['message'];
        }

        $fields = $formSchema['fields'] ?? [];
        $sections = $formSchema['sections'] ?? [['title' => 'Datos', 'fields' => array_keys($fields)]];

        $out = [];
        foreach ($sections as $section) {
            $defs = [];
            foreach ($section['fields'] ?? [] as $name) {
                if (isset($fields[$name])) $defs[$name] = $fields[$name];
            }
            $blocks = $this->blocks($defs, $data, '', null);
            foreach ($blocks as &$b) {
                if (isset($b['title']) && mb_strtolower(trim($b['title'])) === mb_strtolower(trim($section['title'] ?? ''))) {
                    $b['title'] = null;
                }
            }
            unset($b);
            if ($blocks) {
                $out[] = ['title' => $section['title'] ?? '', 'subtitle' => $section['subtitle'] ?? null, 'blocks' => $blocks];
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */

    private function blocks(array $defs, array $scope, string $prefix, ?string $kase): array
    {
        $blocks = [];
        $grid = [];
        $flush = function () use (&$grid, &$blocks) {
            if ($grid) $blocks[] = ['type' => 'grid', 'items' => $grid];
            $grid = [];
        };

        foreach ($defs as $name => $def) {
            if (!is_array($def)) continue;
            $path = $prefix === '' ? $name : "{$prefix}.{$name}";
            $type = $def['type'] ?? 'text';
            $value = $scope[$name] ?? null;

            if (!$this->engine->isVisible($def, $scope, $kase)) continue;

            if ($type === 'object') {
                $flush();
                $blocks[] = [
                    'type' => 'group',
                    'title' => $def['label'] ?? $this->humanize($name),
                    'notes' => $this->notes[$path] ?? [],
                    'blocks' => $this->blocks($def['itemSchema'] ?? [], is_array($value) ? $value : [], $path, null),
                ];
                continue;
            }

            if ($type === 'array') {
                $flush();
                $blocks[] = $this->arrayBlock($name, $def, is_array($value) ? array_values($value) : [], $path);
                continue;
            }

            $grid[] = $this->cell($name, $def, $value, $scope, $kase, $path);
        }
        $flush();
        return $blocks;
    }

    private function arrayBlock(string $name, array $def, array $rows, string $path): array
    {
        $title = $def['label'] ?? $this->humanize($name);
        $itemLabel = $def['item_label'] ?? $title;
        $itemSchema = $def['itemSchema'] ?? [];

        if (!$rows) {
            return ['type' => 'empty', 'title' => $title, 'text' => 'Sin registros'];
        }

        $allLeaves = !array_filter($itemSchema, fn($d) => in_array($d['type'] ?? 'text', ['object', 'array'], true));
        $classifier = $def['classifier'] ?? null;

        // Simple rows (Pagos, Liquidaciones…) → table
        if ($allLeaves && !$classifier) {
            $columns = [];
            foreach ($itemSchema as $col => $cdef) {
                foreach ($rows as $row) {
                    if ($this->engine->isVisible($cdef, is_array($row) ? $row : [], null)) {
                        $columns[$col] = $cdef;
                        break;
                    }
                }
            }
            $tableRows = [];
            $totals = [];
            foreach ($rows as $i => $row) {
                $row = is_array($row) ? $row : [];
                $cells = [];
                foreach ($columns as $col => $cdef) {
                    $cell = $this->cell($col, $cdef, $row[$col] ?? null, $row, null, "{$path}.{$i}.{$col}");
                    $cell['hidden'] = !$this->engine->isVisible($cdef, $row, null);
                    $cells[] = $cell;
                    if (!empty($cdef['money']) && is_numeric($row[$col] ?? null)) {
                        $totals[$col] = ($totals[$col] ?? 0) + (float) $row[$col];
                    }
                }
                $tableRows[] = $cells;
            }
            $totalCells = null;
            if ($totals && count($rows) > 1) {
                $totalCells = [];
                foreach ($columns as $col => $cdef) {
                    $totalCells[] = isset($totals[$col]) ? $this->money($totals[$col], $this->wholeNumber($cdef)) : null;
                }
            }
            return [
                'type' => 'table',
                'title' => $title,
                'columns' => array_map(fn($c) => ['label' => $c['label'] ?? '', 'numeric' => in_array($c['type'] ?? '', ['number', 'computed'], true)], array_values($columns)),
                'rows' => $tableRows,
                'totals' => $totalCells,
            ];
        }

        // Complex rows (personas, operaciones) → one card each
        $cards = [];
        foreach ($rows as $i => $row) {
            $row = is_array($row) ? $row : [];
            $kase = $this->engine->caseForRow($classifier, $row);
            $name = $this->rowName($row);
            $cards[] = [
                'title' => $itemLabel . ' ' . ($i + 1) . ($name ? ' · ' . $name : ''),
                'badge' => $this->rowBadge($itemSchema, $row),
                'blocks' => $this->blocks($itemSchema, $row, "{$path}.{$i}", $kase),
            ];
        }
        return ['type' => 'cards', 'title' => $title, 'count' => count($cards), 'items' => $cards];
    }

    private function cell(string $name, array $def, $value, array $scope, ?string $kase, string $path): array
    {
        $display = $this->display($def, $value);
        return [
            'label' => $def['label'] ?? $this->humanize($name),
            'value' => $display,
            'missing' => $display === null && $this->engine->isRequired($def, $scope, $kase),
            'full' => ($def['col'] ?? null) === 'full' || ($display !== null && mb_strlen($display) > 70),
            'numeric' => in_array($def['type'] ?? '', ['number', 'computed'], true),
            'notes' => $this->notes[$path] ?? [],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Value formatting                                                   */
    /* ------------------------------------------------------------------ */

    private function display(array $def, $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '') || is_array($value)) return null;
        $type = $def['type'] ?? 'text';

        if ($type === 'select') {
            foreach ($def['options'] ?? [] as $opt) {
                if ((string) ($opt['value'] ?? '') === (string) $value) {
                    $label = trim((string) ($opt['label'] ?? ''));
                    if ($label === '' || preg_match('/^seleccion(a|e|ar)\b/iu', $label)) return null;
                    return $label;
                }
            }
            return (string) $value;   // cp_target colonias & values not in options
        }

        if ($type === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $value, $m)) {
            return "{$m[3]}/{$m[2]}/{$m[1]}";
        }

        if (in_array($type, ['number', 'computed'], true) && is_numeric($value)) {
            $n = (float) $value;
            if (!empty($def['money'])) return $this->money($n, $this->wholeNumber($def));
            if (!empty($def['integer']) || ($def['format'] ?? null) === 'round') return number_format($n, 0, '.', ',');
            if (($def['format'] ?? null) === 'decimal') return number_format($n, 2, '.', ',');
            return rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');
        }

        return trim((string) $value);
    }

    private function money(float $n, bool $whole = false): string
    {
        return ($n < 0 ? '-$' : '$') . number_format(abs($n), $whole ? 0 : 2, '.', ',');
    }

    private function wholeNumber(array $def): bool
    {
        return !empty($def['integer']) || ($def['format'] ?? null) === 'round';
    }

    /** "ARTURO ORTEGA ESCUDERO" / "INMOBILIARIA X SA DE CV" for persona rows; null otherwise. */
    private function rowName(array $row): ?string
    {
        if (!empty($row['razon_social']) && is_string($row['razon_social'])) return trim($row['razon_social']);
        $parts = array_filter([
            $row['nombre'] ?? null, $row['apellido_paterno'] ?? null, $row['apellido_materno'] ?? null,
        ], fn($p) => is_string($p) && trim($p) !== '');
        return $parts ? trim(implode(' ', $parts)) : null;
    }

    /** Label of the row's first select that drives its case (tipo_persona / tipo_enajenante…). */
    private function rowBadge(array $itemSchema, array $row): ?string
    {
        foreach ($itemSchema as $name => $def) {
            if (($def['type'] ?? null) === 'select' && str_starts_with($name, 'tipo_')) {
                return $this->display($def, $row[$name] ?? null);
            }
        }
        return null;
    }

    private function humanize(string $name): string
    {
        return ucfirst(str_replace('_', ' ', $name));
    }
}
