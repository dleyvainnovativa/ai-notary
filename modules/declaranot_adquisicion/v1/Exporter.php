<?php

namespace Modules\DeclaranotAdquisicion\V1;

use App\Modules\ExporterContract;

/**
 * DeclaraNOT por adquisición de bienes — SAT layout 910xxx.
 *
 *   Configuracion:{año}|001|035|25|{fecha firma}         (25 = adquisición; 24 = enajenación)
 *   910001-DatosOperacion:escritura|fecha|tipo inmueble|avalúo|monto operación|especifica
 *   910002-DatosAdquirientes-grid:  tipo|rfc|nombre|ap|am|curp|razón social|nacionalidad|fecha nac.|documento|folio
 *   910003-DatosEnajenantes-grid:   (same columns)
 *   910004-DatosPago-grid:ingreso acumulable|ISR|núm. operación|fecha pago
 *   910005-IngresoCopropiedadOSucesion:1|2
 *   910011-PreguntaExisteRepresentanteLegal:1|2
 *   910006-RepresentanteLegal:rfc
 *   910007-DatosCopropiedad-grid:rfc|%|monto operación|avalúo|ingreso acumulable|ISR   (or |||||)
 *   910009-TotalPorcentajeCopropiedad:{suma}                                          (or empty)
 *
 * UTF-8 + LF like DeclaraNOT (no accent stripping). Dates dd/mm/yyyy.
 */
class Exporter implements ExporterContract
{
    /**
     * The SAT example prints persona MORAL rows with 10 columns (no CURP column:
     * razón social right after apellido materno) while the published structure
     * lists 11 for every row. We follow the structure (false). Flip to true if
     * the SAT portal rejects moral rows. Importer accepts both.
     */
    public const MORAL_ROWS_WITHOUT_CURP = false;

    public const PERSONA_COLS = ['tipo', 'rfc', 'nombre', 'apellido_paterno', 'apellido_materno', 'curp',
        'razon_social', 'nacionalidad', 'fecha_nacimiento', 'documento_oficial', 'folio'];

    public function supportedFormats(): array
    {
        return ['txt'];
    }

    public function export(array $validatedData, string $format): string
    {
        if ($format !== 'txt') {
            throw new \InvalidArgumentException('DeclaraNOT por adquisición solo soporta TXT.');
        }
        $d = (isset($validatedData['escritura']) || isset($validatedData['calculo']))
            ? array_merge($validatedData['escritura'] ?? [], $validatedData['calculo'] ?? [])
            : $validatedData;

        $L = [];
        $fecha = $d['fecha_firma_escritura'] ?? null;
        $L[] = 'Configuracion:' . self::year($fecha) . '|001|035|25|' . self::dmy($fecha);

        $L[] = '910001-DatosOperacion:' . self::row([
            $d['numero_escritura'] ?? null,
            self::dmy($fecha),
            $d['tipo_inmueble'] ?? null,
            $d['avaluo_inmueble'] ?? null,
            $d['monto_operacion'] ?? null,
            $d['especifica_inmueble'] ?? null,
        ]);

        foreach (self::list($d['adquirientes'] ?? []) as $p) {
            $L[] = '910002-DatosAdquirientes-grid:' . self::persona($p, 'tipo_adquiriente');
        }
        foreach (self::list($d['enajenantes'] ?? []) as $p) {
            $L[] = '910003-DatosEnajenantes-grid:' . self::persona($p, 'tipo_enajenante');
        }
        foreach (self::list($d['pago'] ?? []) as $p) {
            $L[] = '910004-DatosPago-grid:' . self::row([
                $p['ingreso_acumulable'] ?? null,
                $p['isr_federacion'] ?? null,
                $p['numero_operacion'] ?? null,
                self::dmy($p['fecha_pago'] ?? null),
            ]);
        }

        $cop = is_array($d['copropiedad'] ?? null) ? $d['copropiedad'] : [];
        $rep = is_array($d['representante_comun'] ?? null) ? $d['representante_comun'] : [];
        $integrantes = self::list($cop['integrantes'] ?? []);

        $L[] = '910005-IngresoCopropiedadOSucesion:' . self::s($cop['existe_copropiedad'] ?? null, '2');
        $L[] = '910011-PreguntaExisteRepresentanteLegal:' . self::s($rep['existe_representante_comun'] ?? null, '2');
        $L[] = '910006-RepresentanteLegal:' . self::s($rep['rfc_representante'] ?? null);

        if ($integrantes) {
            $total = 0.0;
            foreach ($integrantes as $i) {
                $total += (float) ($i['porcentaje'] ?? 0);
                $L[] = '910007-DatosCopropiedad-grid:' . self::row([
                    $i['rfc'] ?? null, $i['porcentaje'] ?? null, $i['monto_operacion'] ?? null,
                    $i['valor_avaluo'] ?? null, $i['ingreso_acumulable'] ?? null, $i['isr_federacion'] ?? null,
                ]);
            }
            $L[] = '910009-TotalPorcentajeCopropiedad:' . self::num($total);
        } else {
            $L[] = '910007-DatosCopropiedad-grid:|||||';
            $L[] = '910009-TotalPorcentajeCopropiedad:';
        }

        return implode("\n", $L);
    }

    private static function persona(array $p, string $tipoKey): string
    {
        $cols = [
            $p[$tipoKey] ?? ($p['tipo'] ?? null),
            $p['rfc'] ?? null,
            $p['nombre'] ?? null,
            $p['apellido_paterno'] ?? null,
            $p['apellido_materno'] ?? null,
            $p['curp'] ?? null,
            $p['razon_social'] ?? null,
            $p['nacionalidad'] ?? null,
            self::dmy($p['fecha_nacimiento'] ?? null),
            $p['documento_oficial'] ?? null,
            $p['folio'] ?? null,
        ];
        if (static::MORAL_ROWS_WITHOUT_CURP && self::isMoral($p['rfc'] ?? null)) {
            array_splice($cols, 5, 1);
        }
        return self::row($cols);
    }

    /** RFC de persona moral: 12 caracteres, o el genérico extranjero EXT990101000. */
    public static function isMoral(?string $rfc): bool
    {
        $rfc = strtoupper(trim((string) $rfc));
        return $rfc !== '' && strlen($rfc) === 12;
    }

    private static function row(array $cols): string
    {
        return implode('|', array_map(fn($v) => self::s($v), $cols));
    }

    private static function s($v, string $default = ''): string
    {
        if ($v === null || $v === '') return $default;
        if (is_float($v)) return self::num($v);
        return trim((string) $v);
    }

    private static function num(float $n): string
    {
        return floor($n) == $n ? (string) (int) $n : rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }

    private static function list($v): array
    {
        return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
    }

    /** Y-m-d (or already d/m/Y) → d/m/Y; anything else unchanged. */
    private static function dmy($v): string
    {
        $v = trim((string) $v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return "{$m[3]}/{$m[2]}/{$m[1]}";
        return $v;
    }

    private static function year($v): string
    {
        $v = trim((string) $v);
        if (preg_match('/^(\d{4})-\d{2}-\d{2}/', $v, $m)) return $m[1];
        if (preg_match('#^\d{2}/\d{2}/(\d{4})$#', $v, $m)) return $m[1];
        return '';
    }
}
