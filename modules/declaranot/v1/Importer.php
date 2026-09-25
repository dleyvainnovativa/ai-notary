<?php

namespace Modules\Declaranot\V1;

use App\Modules\ImporterContract;
use App\Modules\ImportException;
use App\Modules\ImportResult;
use App\Services\Import\TxtDecoder;

/**
 * Parses a DeclaraNOT TXT (900001…900013) back into review form data.
 * Column order mirrors Exporter::generateDeclaranotTXT — keep both in sync;
 * ImportService's round-trip check catches any drift on every import.
 * Values are kept as the file's strings (the exporter prints them verbatim).
 */
class Importer implements ImporterContract
{
    private const PERSONA_COLS = ['tipo', 'rfc', 'nombre', 'apellido_paterno', 'apellido_materno', 'curp',
        'razon_social', 'nacionalidad', 'fecha_nacimiento', 'documento_oficial', 'folio'];

    private const PAGO_ISR_COLS = ['ingresos_enajenacion', 'ingresos_exentos', 'ingreso_sismo_2017', 'deducciones_autorizadas',
        'ganancia_perdida', 'years_adquisicion_venta', 'ganancia_acumulable', 'ganancia_no_acumulable', 'isr_federacion',
        'numero_operacion_federacion', 'fecha_pago_federacion', 'isr_entidad', 'numero_operacion_entidad',
        'fecha_pago_entidad', 'total_isr_pagado'];

    private const INTEGRANTE_COLS = ['rfc', 'porcentaje', 'ingresos_enajenacion', 'deducciones_autorizadas', 'ganancia_perdida',
        'ganancia_acumulable', 'ganancia_no_acumulable', 'isr_federacion', 'isr_entidad'];

    public function parse(string $text): ImportResult
    {
        $r = new ImportResult([]);
        $data = [
            'numero_escritura' => null, 'fecha_firma_escritura' => null, 'tipo_inmueble' => null,
            'especifica_inmueble' => null, 'avaluo_inmueble' => null,
            'pagos_inmueble' => [], 'enajenantes' => [], 'adquirientes' => [], 'pago' => [],
            'datos_informativos' => ['ingresos_exentos' => null, 'monto' => null, 'impuesto' => null],
            'copropiedad' => ['existe_copropiedad' => null, 'integrantes' => []],
            'representante_comun' => ['existe_representante_comun' => null, 'rfc_representante' => null],
        ];
        $seen = false;
        $declaredTotal = null;

        foreach (TxtDecoder::lines($text) as $n => $line) {
            $parts = TxtDecoder::splitLine($line);
            if (!$parts) { $r->warn('Línea ' . ($n + 1) . ' no reconocida; se ignoró.'); continue; }
            [$tag, $payload] = $parts;
            $f = explode('|', $payload);

            switch ($tag) {
                case 'Configuracion':
                    $r->meta['notaria'] = ['clave' => $f[1] ?? '', 'entidad' => $f[2] ?? '', 'num_notaria' => $f[3] ?? ''];
                    break;

                case '900001':
                    $seen = true;
                    $data['numero_escritura'] = $this->s($f[0] ?? null);
                    $data['fecha_firma_escritura'] = $this->date($f[1] ?? null, 'fecha_firma_escritura', $r);
                    $data['tipo_inmueble'] = $this->s($f[2] ?? null);
                    $data['especifica_inmueble'] = $this->s($f[3] ?? null);
                    $data['avaluo_inmueble'] = $this->s($f[4] ?? null);
                    break;

                case '900002':
                    $data['pagos_inmueble'][] = [
                        'monto' => $this->s($f[0] ?? null), 'tipo_pago_inmueble' => $this->s($f[1] ?? null),
                        'institucion_financiera' => $this->s($f[2] ?? null), 'numero_cuenta' => $this->s($f[3] ?? null),
                        'otro_pago' => $this->s($f[4] ?? null), 'otro' => null,
                    ];
                    break;

                case '900003': case '900005':
                    $c = $this->combine(self::PERSONA_COLS, $f, $tag, $n, $r);
                    if ($this->allEmpty($c)) break;
                    $tipoKey = $tag === '900003' ? 'tipo_enajenante' : 'tipo_adquiriente';
                    $row = [$tipoKey => $c['tipo']] + array_diff_key($c, ['tipo' => 1]);
                    $data[$tag === '900003' ? 'enajenantes' : 'adquirientes'][] = $row;
                    break;

                case '900004':
                    $data['datos_informativos'] = [
                        'ingresos_exentos' => $this->s($f[0] ?? null), 'monto' => $this->s($f[1] ?? null), 'impuesto' => $this->s($f[2] ?? null),
                    ];
                    break;

                case '900006':
                    $c = $this->combine(self::PAGO_ISR_COLS, $f, $tag, $n, $r);
                    $i = count($data['pago']);
                    $c['fecha_pago_federacion'] = $this->date($c['fecha_pago_federacion'], "pago.{$i}.fecha_pago_federacion", $r);
                    $c['fecha_pago_entidad'] = $this->date($c['fecha_pago_entidad'], "pago.{$i}.fecha_pago_entidad", $r);
                    $data['pago'][] = $c;
                    break;

                case '900010':
                    $data['copropiedad']['existe_copropiedad'] = $this->s($f[0] ?? null);
                    break;

                case '900013':
                    $data['representante_comun']['existe_representante_comun'] = $this->s($f[0] ?? null);
                    break;

                case '900009':
                    $data['representante_comun']['rfc_representante'] = $this->s($f[0] ?? null);
                    break;

                case '900007':
                    $c = $this->combine(self::INTEGRANTE_COLS, $f, $tag, $n, $r);
                    if (!$this->allEmpty($c)) $data['copropiedad']['integrantes'][] = $c;
                    break;

                case '900011':
                    $declaredTotal = trim($f[0] ?? '');
                    break;

                default:
                    $r->warn("Registro {$tag} (línea " . ($n + 1) . ') no reconocido; se ignoró.');
            }
        }

        if (!$seen) {
            throw new ImportException('El archivo no parece ser una DeclaraNOT (falta el registro 900001).');
        }
        if ($declaredTotal !== null && $declaredTotal !== '' && $data['copropiedad']['integrantes']) {
            $sum = array_sum(array_map(fn($i) => (float) ($i['porcentaje'] ?? 0), $data['copropiedad']['integrantes']));
            if (abs($sum - (float) $declaredTotal) > 0.001) {
                $r->warn("El total de copropiedad del archivo ({$declaredTotal}%) no coincide con la suma de los integrantes ({$sum}%).", 'copropiedad');
            }
        }

        $r->data = $data;
        return $r;
    }

    private function combine(array $keys, array $f, string $tag, int $n, ImportResult $r): array
    {
        if (count($f) !== count($keys) && !$this->allEmpty($f)) {
            $r->warn("Registro {$tag} (línea " . ($n + 1) . ') tiene ' . count($f) . ' columnas; se esperaban ' . count($keys) . '.');
        }
        $f = array_pad(array_slice($f, 0, count($keys)), count($keys), '');
        return array_map(fn($v) => $this->s($v), array_combine($keys, $f));
    }

    private function allEmpty(array $values): bool
    {
        foreach ($values as $v) if (trim((string) $v) !== '') return false;
        return true;
    }

    private function s(?string $v): ?string
    {
        return TxtDecoder::nullIfEmpty($v === null ? null : trim($v));
    }

    private function date(?string $v, string $path, ImportResult $r): ?string
    {
        $out = TxtDecoder::ymd($v);
        if ($out !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $out)) {
            $r->warn("Fecha no reconocida: \"{$out}\".", $path);
            return null;
        }
        return $out;
    }
}
