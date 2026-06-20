<?php

namespace App\Services\Schema;

use App\Services\CatalogService;

class SchemaEngine
{
    private array $issues = [];
    private const GENERIC_RFC = [
        'fisica' => ['EXTF900101000'],
        'moral'  => ['EXT990101000'],
    ];

    public function __construct(private CatalogService $catalogs) {}

    public function process(array $schema, array $data, string $moduleDir): EngineResult
    {
        $this->issues = [];
        $fields = $schema['fields'] ?? [];

        $data = $this->applyValueRules($fields, $data);
        $data = $this->applyComputed($fields, $data);
        $data = $this->applyDerived($fields, $data, $moduleDir, $data);
        $this->validate($fields, $data, $moduleDir, '');

        return new EngineResult($data, $this->issues);
    }

    private function personaCase(?string $tipo, ?string $rfc): string
    {
        $r = strtoupper(trim((string) $rfc));
        $t = (string) $tipo;

        if ($t === '1') {
            if (preg_match('/^[A-ZÑ&]{4}\d{6}[A-Z0-9]{3}$/', $r)) return 'nacional_fisica';
            if (preg_match('/^[A-ZÑ&]{3}\d{6}[A-Z0-9]{3}$/', $r)) return 'nacional_moral';
            return 'unknown';
        }
        if ($t === '2') {
            if (in_array($r, self::GENERIC_RFC['fisica'], true)) return 'extranjera_fisica';
            if (in_array($r, self::GENERIC_RFC['moral'], true)) return 'extranjera_moral';
            return 'extranjera_invalid';
        }
        return 'unknown';
    }

    /* ---------- Pass 1: value rules ---------- */
    private function applyValueRules(array $fields, array $data): array
    {
        foreach ($fields as $name => $def) {
            if (($def['type'] ?? null) === 'array' && isset($data[$name]) && is_array($data[$name])) {
                foreach ($data[$name] as $i => $row) {
                    $data[$name][$i] = $this->applyValueRules($def['items'], $row);
                }
                continue;
            }
            if (($def['type'] ?? null) === 'object' && isset($data[$name]) && is_array($data[$name])) {
                $data[$name] = $this->applyValueRules($def['items'], $data[$name]);
                continue;
            }
            if (!empty($def['rules']) && isset($data[$name]) && is_numeric($data[$name])) {
                foreach ($def['rules'] as $rule) {
                    if ($this->evalCondition($rule['condition'] ?? '', $data[$name])) {
                        $data[$name] = $rule['set'];
                    }
                }
            }
            // numeric formatting (format: round, integer, min, max)
            if (isset($data[$name]) && is_numeric($data[$name]) && ($def['type'] ?? null) === 'number') {
                $val = (float) $data[$name];
                if (($def['format'] ?? null) === 'round') $val = round($val);
                if (!empty($def['integer'])) $val = (int) $val;
                if (isset($def['min']) && $val < $def['min']) $val = $def['min'];
                if (isset($def['max']) && $val > $def['max']) $val = $def['max'];
                $data[$name] = $val;
            }
        }
        return $data;
    }

    private function evalCondition(string $condition, $value): bool
    {
        if (preg_match('/value\s*(>=|<=|==|>|<)\s*(-?\d+(\.\d+)?)/', $condition, $m)) {
            [$op, $n] = [$m[1], (float) $m[2]];
            return match ($op) {
                '>=' => $value >= $n,
                '<=' => $value <= $n,
                '>' => $value > $n,
                '<' => $value < $n,
                '==' => $value == $n,
                default => false,
            };
        }
        return false;
    }

    /* ---------- Pass 2: computed ---------- */
    private function applyComputed(array $fields, array $data): array
    {
        foreach ($fields as $name => $def) {
            if (($def['type'] ?? null) === 'array' && isset($data[$name]) && is_array($data[$name])) {
                foreach ($data[$name] as $i => $row) {
                    $data[$name][$i] = $this->applyComputed($def['items'], $row);
                }
                continue;
            }
            if (($def['type'] ?? null) === 'object' && isset($data[$name]) && is_array($data[$name])) {
                $data[$name] = $this->applyComputed($def['items'], $data[$name]);
                continue;
            }
            if (($def['type'] ?? null) === 'computed' && !empty($def['formula'])) {
                $data[$name] = $this->evalFormula($def['formula'], $data);
            }
        }
        return $data;
    }

    private function evalFormula(string $formula, array $row): float
    {
        $sum = 0;
        foreach (explode('+', $formula) as $token) {
            $key = trim($token);
            $sum += (float) ($row[$key] ?? 0);
        }
        return $sum;
    }

    /* ---------- Pass 3: derived ---------- */
    private function applyDerived(array $fields, array $data, string $moduleDir, ?array $root = null): array
    {
        $root = $root ?? $data;

        foreach ($fields as $name => $def) {
            if (($def['type'] ?? null) === 'object' && isset($data[$name]) && is_array($data[$name])) {
                $data[$name] = $this->applyDerived($def['items'], $data[$name], $moduleDir, $root);
                continue;
            }
            if (!empty($def['derive_from_array_length'])) {
                $rule = $def['derive_from_array_length'];
                $arr = data_get($root, $rule['path']);
                $len = is_array($arr) ? count($arr) : 0;
                $over = $len > ($rule['if_gt'] ?? 0);

                if (isset($rule['true_value']) || isset($rule['false_value'])) {
                    // value-based (preferred)
                    $data[$name] = $over ? ($rule['true_value'] ?? null) : ($rule['false_value'] ?? null);
                } else {
                    // legacy label-based fallback
                    $label = $over ? ($rule['true_label'] ?? null) : ($rule['false_label'] ?? null);
                    $data[$name] = $this->labelToValue($def, $label, $moduleDir);
                }
            }
        }
        return $data;
    }

    private function labelToValue(array $def, string $label, string $moduleDir): ?string
    {
        if (empty($def['options']) && empty($def['source'])) return $label;

        // formSchema embeds options directly; extraction schema uses source
        $options = $def['options'] ?? ($this->catalogs->load($def['source'], $moduleDir) ?? []);
        foreach ($options as $opt) {
            if (($opt['label'] ?? null) === $label) return (string) $opt['value'];
        }
        return $label;
    }

    /* ---------- Pass 4: validation ---------- */
    private function validate(array $fields, array $data, string $moduleDir, string $path): void
    {
        foreach ($fields as $name => $def) {
            $fieldPath = $path === '' ? $name : "{$path}.{$name}";
            $value = $data[$name] ?? null;
            $type = $def['type'] ?? 'text';

            if ($type === 'array') {
                if (!empty($def['enabled_if']) && !$this->conditionMet($def['enabled_if'], $data)) {
                    continue;
                }
                $classifier = $def['classifier'] ?? null;
                foreach (($value ?? []) as $i => $row) {
                    $kase = null;
                    if ($classifier) {
                        $kase = $this->personaCase(
                            $row[$classifier['tipo_field']] ?? null,
                            $row[$classifier['rfc_field']] ?? null
                        );
                        if ($kase === 'extranjera_invalid' && !$this->isEmpty($row[$classifier['rfc_field']] ?? null)) {
                            $this->addIssue(
                                "{$fieldPath}.{$i}.{$classifier['rfc_field']}",
                                $classifier['rfc_field'],
                                'rfc_generic',
                                'Para extranjeros, use un RFC genérico (EXTF900101000 o EXT990101000).'
                            );
                        }
                    }
                    $this->validateRow($def['items'], $row, $moduleDir, "{$fieldPath}.{$i}", $kase);
                }
                continue;
            }

            if ($type === 'object') {
                $this->validate($def['items'], $value ?? [], $moduleDir, $fieldPath);
                continue;
            }

            $this->validateLeaf($name, $def, $value, $data, $moduleDir, $fieldPath);
        }
    }

    private function validateRow(array $fields, array $data, string $moduleDir, string $path, ?string $kase): void
    {
        foreach ($fields as $name => $def) {
            $fieldPath = "{$path}.{$name}";
            $value = $data[$name] ?? null;

            // case-based requirement: if field declares cases and the row's case
            // isn't among them, the field is hidden → skip entirely.
            if (!empty($def['required_in_cases']) || !empty($def['show_in_cases'])) {
                $requiredHere = $kase && in_array($kase, $def['required_in_cases'] ?? [], true);
                $shownHere = $requiredHere || ($kase && in_array($kase, $def['show_in_cases'] ?? [], true));

                if (!$shownHere) {
                    continue; // hidden field → don't validate
                }
                if ($requiredHere && $this->isEmpty($value)) {
                    $this->addIssue($fieldPath, $name, 'required_case', 'Este campo es obligatorio.');
                    continue;
                }
                // shown & filled (or shown-optional) → fall through to format checks
            }

            $this->validateLeaf($name, $def, $value, $data, $moduleDir, $fieldPath);
        }
    }

    /* ---------- Shared per-field validation ---------- */
    private function validateLeaf(string $name, array $def, $value, array $scope, string $moduleDir, string $fieldPath): void
    {
        $type = $def['type'] ?? 'text';

        // required_if
        if (!empty($def['required_if'])) {
            if ($this->conditionMet($def['required_if'], $scope)) {
                if ($this->isEmpty($value)) {
                    $this->addIssue($fieldPath, $name, 'required_if', 'El campo es requerido.');
                    return;
                }
            } else {
                if ($this->isEmpty($value)) return; // not required & empty → done
            }
        }

        // required_when
        if (!empty($def['required_when'])) {
            if ($this->evalRequiredWhen($def, $scope) && $this->isEmpty($value)) {
                $this->addIssue($fieldPath, $name, 'required_when', 'Este campo es obligatorio.');
                return;
            }
        }

        // required
        if (!empty($def['required']) && $this->isEmpty($value)) {
            $this->addIssue($fieldPath, $name, 'required', 'El campo es requerido.');
            return;
        }

        if ($this->isEmpty($value)) return;

        // catalog membership (formSchema embeds options; extraction schema uses source)
        if ($type === 'select') {
            $options = $def['options'] ?? null;
            if ($options !== null) {
                $ok = false;
                foreach ($options as $opt) {
                    if ((string) ($opt['value'] ?? null) === (string) $value) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) $this->addIssue($fieldPath, $name, 'catalog', 'Valor no permitido.');
            } elseif (!empty($def['source'])) {
                if (!$this->catalogs->isValidValue($def['source'], $moduleDir, $value)) {
                    $this->addIssue($fieldPath, $name, 'catalog', 'Valor no permitido.');
                }
            }
        }

        // format
        if (!empty($def['validation']['format'])) {
            if (!$this->validFormat($def['validation']['format'], (string) $value)) {
                $this->addIssue($fieldPath, $name, 'format', strtoupper($def['validation']['format']) . ' inválido.');
            }
        }

        // integer
        if ($type === 'number' && !empty($def['integer']) && preg_match('/[.,]/', (string) $value)) {
            $this->addIssue($fieldPath, $name, 'integer', 'Solo números enteros.');
        }

        // min / max
        if ($type === 'number' && is_numeric($value)) {
            if (isset($def['min']) && $value < $def['min']) {
                $this->addIssue($fieldPath, $name, 'min', "Mínimo: {$def['min']}.");
            }
            if (isset($def['max']) && $value > $def['max']) {
                $this->addIssue($fieldPath, $name, 'max', "Máximo: {$def['max']}.");
            }
        }

        // date
        if ($type === 'date' && !$this->validDate((string) $value)) {
            $this->addIssue($fieldPath, $name, 'format', 'Fecha inválida (YYYY-MM-DD).');
        }
    }

    private function conditionMet(array $condition, array $data): bool
    {
        foreach ($condition as $field => $expected) {
            $actual = $data[$field] ?? null;

            if (is_array($expected) && isset($expected['op'])) {
                if (!$this->compare($actual, $expected['op'], $expected['value'])) return false;
                continue;
            }

            $allowed = is_array($expected) ? $expected : [$expected];
            if (!in_array((string) $actual, array_map('strval', $allowed), true)) return false;
        }
        return true;
    }

    private function compare($actual, string $op, $target): bool
    {
        if (!is_numeric($actual)) return false;
        $a = (float) $actual;
        $t = (float) $target;
        return match ($op) {
            '>'  => $a > $t,
            '>=' => $a >= $t,
            '<'  => $a < $t,
            '<=' => $a <= $t,
            '==' => $a == $t,
            '!=' => $a != $t,
            default => false,
        };
    }

    private function validFormat(string $format, string $value): bool
    {
        return match ($format) {
            'rfc'  => (bool) preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/i', $value),
            'curp' => (bool) preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/i', $value),
            default => true,
        };
    }

    private function validDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    private function isEmpty($value): bool
    {
        return $value === null || $value === '' || (is_array($value) && empty($value));
    }

    private function addIssue(string $path, string $field, string $rule, string $message): void
    {
        $this->issues[] = new ValidationIssue($path, $field, $rule, $message);
    }

    private function evalRequiredWhen(array $def, array $data): bool
    {
        $rw = $def['required_when'] ?? null;
        if (!$rw) return false;
        $value = $data[$rw['field']] ?? null;

        return match ($rw['rule']) {
            'rfc_invalid_or_generic_moral' => $this->rfcInvalidOrGenericMoral($value),
            default => false,
        };
    }

    private function rfcInvalidOrGenericMoral($value): bool
    {
        $rfc = strtoupper(trim((string) $value));
        if ($rfc === '') return true;
        if ($rfc === 'XEXX010101000') return true;
        return !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc);
    }
}
