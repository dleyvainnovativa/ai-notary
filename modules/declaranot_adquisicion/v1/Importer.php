<?php

namespace Modules\DeclaranotAdquisicion\V1;

use App\Modules\ImporterContract;
use App\Modules\ImportException;
use App\Modules\ImportResult;
use App\Services\Import\TxtDecoder;

require_once __DIR__ . '/Exporter.php';

/**
 * Parses a DeclaraNOT por adquisición TXT (910001…910011) back into review form
 * data. Column order mirrors Exporter — ImportService's round-trip check catches drift.
 * Persona MORAL rows with 10 columns (no CURP, as in the SAT example) are accepted.
 */
class Importer implements ImporterContract
{
    private const PAGO_COLS = ['ingreso_acumulable', 'isr_federacion', 'numero_operacion', 'fecha_pago'];
    private const INTEGRANTE_COLS = ['rfc', 'porcentaje', 'monto_operacion', 'valor_avaluo', 'ingreso_acumulable', 'isr_federacion'];

    public function parse(string $text): ImportResult
    {
        $r = new ImportResult([]);
        $data = [
            'numero_escritura' => null, 'fecha_firma_escritura' => null, 'tipo_inmueble' => null,
            'especifica_inmueble' => null, 'avaluo_inmueble' => null, 'monto_operacion' => null,
            'adquirientes' => [], 'enajenantes' => [], 'pago' => [],
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
                    $r->meta['configuracion'] = ['tipo_declaracion' => $f[1] ?? '', 'clave' => $f[2] ?? '', 'tipo' => $f[3] ?? ''];
                    if (($f[3] ?? '') !== '' && $f[3] !== '25') {
                        $r->warn("El archivo indica tipo de declaración {$f[3]} (25 = adquisición de bienes).");
                    }
                    break;

                case '910001':
                    $seen = true;
                    $data['numero_escritura'] = $this->s($f[0] ?? null);
                    $data['fecha_firma_escritura'] = $this->date($f[1] ?? null, 'fecha_firma_escritura', $r);
                    $data['tipo_inmueble'] = $this->s($f[2] ?? null);
                    $data['avaluo_inmueble'] = $this->s($f[3] ?? null);
                    $data['monto_operacion'] = $this->s($f[4] ?? null);
                    $data['especifica_inmueble'] = $this->s($f[5] ?? null);
                    break;

                case '910002': case '910003':
                    $list = $tag === '910002' ? 'adquirientes' : 'enajenantes';
                    $tipoKey = $tag === '910002' ? 'tipo_adquiriente' : 'tipo_enajenante';
                    $cols = Exporter::PERSONA_COLS;
                    if (count($f) === count($cols) - 1 && Exporter::isMoral($f[1] ?? null)) {
                        array_splice($f, 5, 0, ['']);   // moral row without CURP column
                    }
                    $c = $this->combine($cols, $f, $tag, $n, $r);
                    if ($this->allEmpty($c)) break;
                    $i = count($data[$list]);
                    $c['fecha_nacimiento'] = $this->date($c['fecha_nacimiento'], "{$list}.{$i}.fecha_nacimiento", $r);
                    $data[$list][] = [$tipoKey => $c['tipo']] + array_diff_key($c, ['tipo' => 1]);
                    break;

                case '910004':
                    $c = $this->combine(self::PAGO_COLS, $f, $tag, $n, $r);
                    if ($this->allEmpty($c)) break;
                    $i = count($data['pago']);
                    $c['fecha_pago'] = $this->date($c['fecha_pago'], "pago.{$i}.fecha_pago", $r);
                    $data['pago'][] = $c;
                    break;

                case '910005':
                    $data['copropiedad']['existe_copropiedad'] = $this->s($f[0] ?? null);
                    break;

                case '910011':
                    $data['representante_comun']['existe_representante_comun'] = $this->s($f[0] ?? null);
                    break;

                case '910006':
                    $data['representante_comun']['rfc_representante'] = $this->s($f[0] ?? null);
                    break;

                case '910007':
                    $c = $this->combine(self::INTEGRANTE_COLS, $f, $tag, $n, $r);
                    if (!$this->allEmpty($c)) $data['copropiedad']['integrantes'][] = $c;
                    break;

                case '910009':
                    $declaredTotal = trim($f[0] ?? '');
                    break;

                default:
                    $r->warn("Registro {$tag} (línea " . ($n + 1) . ') no reconocido; se ignoró.');
            }
        }

        if (!$seen) {
            throw new ImportException('El archivo no parece ser una DeclaraNOT por adquisición de bienes (falta el registro 910001).');
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
