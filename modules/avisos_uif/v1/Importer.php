<?php

namespace Modules\AvisosUif\V1;

use App\Modules\ImporterContract;
use App\Modules\ImportException;
use App\Modules\ImportResult;
use App\Services\Import\TxtDecoder;

require_once __DIR__ . '/Exporter.php';

/**
 * Parses a UIF aviso TXT (930001…930017) back into review form data.
 *
 * Persona column order is NOT repeated here: it is read from the Exporter's own
 * column maps (fisicaCols / moralCols / repCols), so import and export can never
 * drift apart. GUIDs are not stored — the exporter regenerates them from the
 * referencia; ImportService's round-trip check reports if that changes the file.
 */
class Importer implements ImporterContract
{
    private array $fisicaKeys;
    private array $moralKeys;
    private array $repKeys;

    public function __construct()
    {
        $exporter = new Exporter();
        $keys = function (string $method) use ($exporter) {
            $m = new \ReflectionMethod($exporter, $method);
            $m->setAccessible(true);
            return array_keys($m->invoke($exporter));
        };
        $this->fisicaKeys = $keys('fisicaColsEmpty');
        $this->moralKeys = $keys('moralColsEmpty');
        $this->repKeys = $keys('repColsEmpty');
    }

    public function parse(string $text): ImportResult
    {
        $result = new ImportResult([]);
        $data = [
            'referencia_aviso' => null, 'prioridad' => null, 'tipo_alerta' => null, 'descripcion_alerta' => null,
            'solicitante' => [], 'operaciones_acumuladas' => null, 'operaciones' => [],
        ];
        $declaredOps = null;
        $op = -1;                 // index of the current operation
        $inmGuidToOp = [];
        $lastMoral = [];          // side => index of last persona moral (for its representante line)
        $seen930002 = false;

        foreach (TxtDecoder::lines($text) as $n => $line) {
            $parts = TxtDecoder::splitLine($line);
            if (!$parts) { $result->warn('Línea ' . ($n + 1) . ' no reconocida; se ignoró.'); continue; }
            [$tag, $payload] = $parts;
            $f = explode('|', $payload);

            switch ($tag) {
                case 'Configuracion':
                    // AÑO|clave|entidad|num_notaria|fecha
                    $result->meta['notaria'] = ['clave' => $f[1] ?? '', 'entidad' => $f[2] ?? '', 'num_notaria' => $f[3] ?? ''];
                    break;

                case '930001':
                    break;

                case '930002':
                    $seen930002 = true;
                    $data['referencia_aviso'] = $this->s($f[0] ?? null);
                    $data['prioridad'] = $this->s($f[1] ?? null);
                    $data['tipo_alerta'] = $this->s($f[2] ?? null);
                    $data['descripcion_alerta'] = $this->s($f[3] ?? null);
                    break;

                case '930003':
                    $data['solicitante'] = [
                        'rfc' => $this->s($f[0] ?? null), 'curp' => $this->s($f[1] ?? null),
                        'fecha_nacimiento' => $this->date($f[2] ?? null, 'solicitante.fecha_nacimiento', $result),
                        'nombre' => $this->s($f[3] ?? null), 'apellido_paterno' => $this->s($f[4] ?? null),
                        'apellido_materno' => $this->s($f[5] ?? null),
                    ];
                    break;

                case '930004':
                    $declaredOps = (int) ($f[0] ?? 0);
                    break;

                case '930005':
                    $op++;
                    $lastMoral = [];
                    $data['operaciones'][$op] = [
                        'fecha_operacion' => $this->date($f[0] ?? null, "operaciones.{$op}.fecha_operacion", $result),
                        'tipo_transmision' => $this->s($f[1] ?? null),
                        'adquirentes' => [], 'vendedores' => [], 'inmueble' => [], 'pagos' => [],
                    ];
                    break;

                case '930006': case '930011':
                    $this->requireOp($op, $tag);
                    $side = $tag === '930006' ? 'adquirentes' : 'vendedores';
                    $c = $this->cols($this->fisicaKeys, $f, $tag, $n, $result);
                    if ($this->allEmpty($c)) break;   // placeholder line
                    $i = count($data['operaciones'][$op][$side]);
                    $data['operaciones'][$op][$side][] = $this->fisica($c, "operaciones.{$op}.{$side}.{$i}", $result);
                    break;

                case '930007': case '930012':
                    $this->requireOp($op, $tag);
                    $side = $tag === '930007' ? 'adquirentes' : 'vendedores';
                    $c = $this->cols($this->moralKeys, $f, $tag, $n, $result);
                    if ($this->allEmpty($c)) break;
                    $i = count($data['operaciones'][$op][$side]);
                    $data['operaciones'][$op][$side][] = $this->moral($c, "operaciones.{$op}.{$side}.{$i}", $result);
                    $lastMoral[$side] = $i;
                    break;

                case '930008': case '930013':
                    $this->requireOp($op, $tag);
                    $side = $tag === '930008' ? 'adquirentes' : 'vendedores';
                    $c = $this->cols($this->repKeys, $f, $tag, $n, $result);
                    if ($this->allEmpty($c)) break;
                    if (!isset($lastMoral[$side])) {
                        $result->warn("Representante legal (línea " . ($n + 1) . ") sin persona moral a la que pertenezca; se ignoró.");
                        break;
                    }
                    $i = $lastMoral[$side];
                    $data['operaciones'][$op][$side][$i]['representante'] = [
                        'rfc' => $this->s($c['rfc']), 'curp' => $this->s($c['curp']),
                        'fecha_nacimiento' => TxtDecoder::ymd($c['fecha_nacimiento']),
                        'nombre' => $this->s($c['nombre']), 'apellido_paterno' => $this->s($c['apellido_paterno']),
                        'apellido_materno' => $this->s($c['apellido_materno']),
                    ];
                    $result->warn('Trae representante legal de la persona moral; el formulario aún no lo muestra, pero se conserva al exportar mientras no se elimine la persona.', "operaciones.{$op}.{$side}.{$i}");
                    break;

                case '930009': case '930010': case '930014': case '930015':
                    if (!$this->allEmpty($f)) {
                        $result->warn('El archivo trae datos de fideicomiso (' . $tag . '); aún no se admiten y se perderán al exportar.');
                    }
                    break;

                case '930016':
                    $this->requireOp($op, $tag);
                    // tipo_bien|valor_pactado|m2_terreno|m2_construidos|folio_real|num_instrumento|valor_avaluo|(vacío)|entidad|calle|num_ext|num_int|cp|colonia|municipio|opGuid|inmGuid
                    $data['operaciones'][$op]['inmueble'] = [
                        'tipo_bien' => $this->s($f[0] ?? null),
                        'valor_pactado' => $this->num($f[1] ?? null),
                        'm2_terreno' => $this->num($f[2] ?? null),
                        'm2_construidos' => $this->num($f[3] ?? null),
                        'folio_real' => $this->s($f[4] ?? null),
                        'num_instrumento' => $this->s($f[5] ?? null),
                        'valor_avaluo' => $this->num($f[6] ?? null),
                        'domicilio' => [
                            'entidad_federativa' => $this->s($f[8] ?? null), 'calle' => $this->s($f[9] ?? null),
                            'num_ext' => $this->s($f[10] ?? null), 'num_int' => $this->s($f[11] ?? null),
                            'codigo_postal' => $this->s($f[12] ?? null), 'colonia' => $this->s($f[13] ?? null),
                            'municipio' => $this->s($f[14] ?? null),
                        ],
                    ];
                    if (!empty($f[16])) $inmGuidToOp[$f[16]] = $op;
                    break;

                case '930017':
                    // fecha|forma|instrumento|moneda|monto|inmGuid — attach to the inmueble's operation
                    $target = $inmGuidToOp[$f[5] ?? ''] ?? $op;
                    $this->requireOp($target, $tag);
                    $p = count($data['operaciones'][$target]['pagos']);
                    $data['operaciones'][$target]['pagos'][] = [
                        'fecha_pago' => $this->date($f[0] ?? null, "operaciones.{$target}.pagos.{$p}.fecha_pago", $result),
                        'forma_pago' => $this->s($f[1] ?? null), 'instrumento' => $this->s($f[2] ?? null),
                        'moneda' => $this->s($f[3] ?? null), 'monto' => $this->num($f[4] ?? null),
                    ];
                    break;

                default:
                    $result->warn("Registro {$tag} (línea " . ($n + 1) . ') no reconocido; se ignoró.');
            }
        }

        if (!$seen930002 || $op < 0) {
            throw new ImportException('El archivo no parece ser un aviso UIF (faltan los registros 930002 / 930005).');
        }
        $count = count($data['operaciones']);
        if ($declaredOps !== null && $declaredOps !== $count) {
            $result->warn("El archivo declara {$declaredOps} operación(es) (930004) pero contiene {$count}.");
        }
        $data['operaciones_acumuladas'] = $count > 1 ? '1' : '2';

        $result->data = $data;
        $result->meta['operations'] = $count;
        return $result;
    }

    /* ------------------------------------------------------------------ */

    private function fisica(array $c, string $path, ImportResult $r): array
    {
        return [
            'tipo_persona' => '1',
            'rfc' => $this->s($c['rfc']), 'curp' => $this->s($c['curp']),
            'fecha_nacimiento' => $this->date($c['fecha_nacimiento'], "{$path}.fecha_nacimiento", $r),
            'nombre' => $this->s($c['nombre']), 'apellido_paterno' => $this->s($c['apellido_paterno']),
            'apellido_materno' => $this->s($c['apellido_materno']),
            'nacionalidad' => $this->s($c['pais_nacionalidad']),
            'actividad_economica' => $this->s($c['actividad_economica']),
            'razon_social' => null, 'fecha_constitucion' => null, 'giro_mercantil' => null, 'numero_fideicomiso' => null,
            'domicilio' => $this->domicilio($c, "{$path}.domicilio", $r),
        ];
    }

    private function moral(array $c, string $path, ImportResult $r): array
    {
        return [
            'tipo_persona' => '2',
            'rfc' => $this->s($c['rfc']), 'razon_social' => $this->s($c['razon_social']),
            'fecha_constitucion' => $this->date($c['fecha_constitucion'], "{$path}.fecha_constitucion", $r),
            'nacionalidad' => $this->s($c['pais_nacionalidad']),
            'giro_mercantil' => $this->s($c['giro_mercantil']),
            'curp' => null, 'fecha_nacimiento' => null, 'nombre' => null, 'apellido_paterno' => null,
            'apellido_materno' => null, 'actividad_economica' => null, 'numero_fideicomiso' => null,
            'domicilio' => $this->domicilio($c, "{$path}.domicilio", $r),
        ];
    }

    private function domicilio(array $c, string $path, ImportResult $r): array
    {
        $tipo = $this->s($c['tipo_domicilio']);
        if ($tipo === '2') {
            $d = [
                'tipo_domicilio' => '2', 'entidad_federativa' => null,
                'calle' => $this->s($c['ext_calle']), 'num_ext' => $this->s($c['ext_num_ext']), 'num_int' => $this->s($c['ext_num_int']),
                'codigo_postal' => $this->s($c['ext_cp']), 'colonia' => $this->s($c['ext_colonia']), 'municipio' => null,
                'pais' => $this->s($c['ext_pais']), 'estado' => $this->s($c['ext_estado']), 'ciudad' => $this->s($c['ext_ciudad']),
                'telefono' => $this->s($c['ext_telefono']), 'correo' => $this->s($c['ext_correo']),
            ];
            $lost = array_filter(['país' => $d['pais'], 'estado' => $d['estado'], 'ciudad' => $d['ciudad'], 'teléfono' => $d['telefono'], 'correo' => $d['correo']]);
        } else {
            $d = [
                'tipo_domicilio' => $tipo, 'entidad_federativa' => $this->s($c['nac_entidad']),
                'calle' => $this->s($c['nac_calle']), 'num_ext' => $this->s($c['nac_num_ext']), 'num_int' => $this->s($c['nac_num_int']),
                'codigo_postal' => $this->s($c['nac_cp']), 'colonia' => $this->s($c['nac_colonia']), 'municipio' => $this->s($c['nac_municipio']),
                'telefono' => $this->s($c['nac_telefono']), 'correo' => $this->s($c['nac_correo']),
            ];
            $lost = array_filter(['teléfono' => $d['telefono'], 'correo' => $d['correo']]);
        }
        if ($lost) {
            $r->warn('El archivo trae ' . implode(', ', array_keys($lost)) . ' del domicilio; el formulario no tiene esos campos y no se incluirán si exportas desde el formulario.', $path);
        }
        return $d;
    }

    private function cols(array $keys, array $f, string $tag, int $n, ImportResult $r): array
    {
        if (count($f) !== count($keys) && !$this->allEmpty($f)) {
            $r->warn("Registro {$tag} (línea " . ($n + 1) . ') tiene ' . count($f) . ' columnas; se esperaban ' . count($keys) . '.');
        }
        $f = array_pad(array_slice($f, 0, count($keys)), count($keys), '');
        return array_combine($keys, $f);
    }

    private function requireOp(int $op, string $tag): void
    {
        if ($op < 0) throw new ImportException("El registro {$tag} aparece antes de cualquier operación (930005).");
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

    private function num(?string $v): int|float|null
    {
        $v = trim((string) $v);
        if ($v === '' || !is_numeric($v)) return null;
        return $v + 0;
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
