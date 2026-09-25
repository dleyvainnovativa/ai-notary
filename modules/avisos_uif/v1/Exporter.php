<?php

namespace Modules\AvisosUif\V1;

use App\Modules\ExporterContract;
use Carbon\Carbon;

class Exporter implements ExporterContract
{
    private const GENERIC_RFC = ['fisica' => ['EXTF900101000'], 'moral' => ['EXT990101000']];

    public function supportedFormats(): array
    {
        return ['txt', 'xml'];
    }

    public function export(array $data, string $format): string
    {
        return match ($format) {
            'txt' => $this->generateTXT($data),
            'xml' => $this->generateTXT($data), // TODO: real XML when spec available
            default => throw new \InvalidArgumentException("Formato no soportado: {$format}"),
        };
    }

    private function generateTXT(array $json): string
    {
        $v = fn($x) => ($x ?? '') === null ? '' : (string) ($x ?? '');
        $date = fn($x) => $x ? Carbon::parse($x)->format('d/m/Y') : '';

        $notaria = $json['_notaria'] ?? ['clave' => '001', 'entidad' => '035', 'num_notaria' => '28'];
        $referencia = $v($json['referencia_aviso'] ?? '');
        $guidPrefix = $this->guidPrefix($referencia);   // e.g. "02660000"

        // Determine aviso date from the first operation (fallback: today)
        $firstOp = $json['operaciones'][0] ?? [];
        $avisoDate = $date($firstOp['fecha_operacion'] ?? now());

        $lines = [];

        // Configuracion:AÑO|clave|entidad|num_notaria|fecha
        $anio = $firstOp['fecha_operacion'] ? Carbon::parse($firstOp['fecha_operacion'])->format('Y') : now()->format('Y');
        $lines[] = "Configuracion:{$anio}|{$notaria['clave']}|{$notaria['entidad']}|{$notaria['num_notaria']}|{$avisoDate}";

        // 930001
        $lines[] = '930001-DatosIdentificacion:';

        // 930002 — referencia|prioridad|tipoAlerta|desc||
        // Real avisos always carry prioridad=1, tipoAlerta=100. Default when blank.
        $lines[] = '930002-Datos del Aviso:' . $referencia . '|' .
            ($v($json['prioridad'] ?? '') ?: '1') . '|' .
            ($v($json['tipo_alerta'] ?? '') ?: '100') . '|' .
            $v($json['descripcion_alerta'] ?? '') . '||';

        // 930003 — solicitante
        $s = $json['solicitante'] ?? [];
        $lines[] = '930003-Datos persona solicita formalizacion-grid:' .
            $v($s['rfc'] ?? '') . '|' . $v($s['curp'] ?? '') . '|' . $date($s['fecha_nacimiento'] ?? null) . '|' .
            $v($s['nombre'] ?? '') . '|' . $v($s['apellido_paterno'] ?? '') . '|' . $v($s['apellido_materno'] ?? '');

        // 930004 — number of operations (examples show "2"; using op count — confirm)
        $ops = $json['operaciones'] ?? [];
        $lines[] = '930004-DetalleOperaciones:' . count($ops);

        // Per operation — but the TXT examples only ever show one operation's blocks.
        // We emit for each operation; SAT files in examples have one op.
        $opCounter = 0;
        foreach ($ops as $op) {
            $opCounter++;
            $opGuid = $this->guid($guidPrefix, 0, $opCounter);   // ...-0000-...-00000000000N

            // 930005 — operacion: fecha|tipoTransmision|opGuid
            $lines[] = '930005-Datos de la operacion-grid:' .
                $date($op['fecha_operacion'] ?? null) . '|' .
                $v($op['tipo_transmision'] ?? '') . '|' . $opGuid;

            $personGuidSeq = 1; // increments across all persons in this op

            // Split adquirentes (compradores) by persona_case
            [$compFisicas, $compMorales] = $this->splitByCase($op['adquirentes'] ?? []);
            // 930006 comprador física  |  930007 comprador moral (+930008 rep)
            $personGuidSeq = $this->emitPersonasFisicas($lines, '930006-Datos comprador Persona Fisica-grid', $compFisicas, $guidPrefix, $opGuid, $personGuidSeq, $v, $date);
            $personGuidSeq = $this->emitPersonasMorales($lines, '930007-Datos comprador Persona Moral-grid', '930008-Representante legal realiza operacion a nombre persona moral-grid', $compMorales, $guidPrefix, $opGuid, $personGuidSeq, $v, $date);

            // empty fideicomiso lines (930009/930010) — examples always empty
            // empty fideicomiso lines (930009/930010). Real avisos: 25 and 7 cols.
            $lines[] = '930009-Datos comprador fideicomiso-grid:' . str_repeat('|', 24);
            $lines[] = '930010-Representante legal realiza operacion a nombre fideicomiso-grid:' . str_repeat('|', 6);

            // Split vendedores by persona_case
            [$vendFisicas, $vendMorales] = $this->splitByCase($op['vendedores'] ?? []);
            $personGuidSeq = $this->emitPersonasFisicas($lines, '930011-Datos vendedor Persona Fisica-grid', $vendFisicas, $guidPrefix, $opGuid, $personGuidSeq, $v, $date);
            $personGuidSeq = $this->emitPersonasMorales($lines, '930012-Datos vendedor Persona Moral-grid', '930013-Representante legal realiza operacion a nombre persona moral-grid', $vendMorales, $guidPrefix, $opGuid, $personGuidSeq, $v, $date);

            $lines[] = '930014-Datos vendedor fideicomiso-grid:' . str_repeat('|', 24);
            $lines[] = '930015-Representante legal realiza operacion a nombre fideicomiso-grid:' . str_repeat('|', 6);

            // 930016 — inmueble
            $inm = $op['inmueble'] ?? [];
            $dom = $inm['domicilio'] ?? [];
            $inmGuid = $this->guid($guidPrefix, 1, 1); // third group = 0001
            $lines[] = '930016-Datos inmuebles-grid:' .
                $v($inm['tipo_bien'] ?? '') . '|' .
                $this->money($inm['valor_pactado'] ?? 0) . '|' .
                $this->money($inm['m2_terreno'] ?? 0) . '|' .
                $this->money($inm['m2_construidos'] ?? 0) . '|' .
                $v($inm['folio_real'] ?? '') . '|' .
                $v($inm['num_instrumento'] ?? '') . '|' .
                $this->money($inm['valor_avaluo'] ?? 0) . '|' .
                '|' .   // empty column (examples show it)
                $v($dom['entidad_federativa'] ?? '') . '|' .
                $v($dom['calle'] ?? '') . '|' .
                $v($dom['num_ext'] ?? '') . '|' .
                $v($dom['num_int'] ?? '') . '|' .
                $v($dom['codigo_postal'] ?? '') . '|' .
                $v($dom['colonia'] ?? '') . '|' .
                $v($dom['municipio'] ?? '') . '|' .
                $opGuid . '|' . $inmGuid;

            // 930017 — liquidaciones (one line per pago)
            foreach (($op['pagos'] ?? []) as $pago) {
                $lines[] = '930017-Datos inmuebles liquidaciones-grid:' .
                    $date($pago['fecha_pago'] ?? null) . '|' .
                    $v($pago['forma_pago'] ?? '') . '|' .
                    $v($pago['instrumento'] ?? '') . '|' .
                    $v($pago['moneda'] ?? '') . '|' .
                    $this->money($pago['monto'] ?? 0) . '|' .
                    $inmGuid;
            }
        }

        // SAT files use CRLF and Windows-1252 encoding
        $text = implode("\r\n", $lines);
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    /* ---------- persona emission ----------
     *
     * Column order defined once, by name, in the *Cols() helpers, joined with
     * row()/implode. Empty and populated variants share the same key list so they
     * cannot drift out of alignment. VERIFIED byte-for-byte against real SAT
     * avisos (026640/57/58/59): física=29, moral=27, rep=7 cols.
     */

    private function splitByCase(array $personas): array
    {
        $fisicas = [];
        $morales = [];
        foreach ($personas as $p) {
            $case = $this->personaCase($p['tipo_persona'] ?? null, $p['rfc'] ?? null);
            if (str_contains($case, 'moral')) $morales[] = $p;
            else $fisicas[] = $p; // fisica + unknown default to física
        }
        return [$fisicas, $morales];
    }

    /** Join an ordered, named column map into a pipe-delimited row body. */
    private function row(array $cols): string
    {
        return implode('|', array_values($cols));
    }

    /** Física person columns (930006/930011), manual order, verified vs real avisos. */
    private function fisicaCols(array $p, string $opGuid, $v, $date): array
    {
        $d = $p['domicilio'] ?? [];
        $esExtranjero = ($v($d['tipo_domicilio'] ?? '') === '2');

        return [
            'rfc'                 => $v($p['rfc'] ?? ''),
            'curp'                => $v($p['curp'] ?? ''),
            'fecha_nacimiento'    => $date($p['fecha_nacimiento'] ?? null),
            'nombre'              => $v($p['nombre'] ?? ''),
            'apellido_paterno'    => $v($p['apellido_paterno'] ?? ''),
            'apellido_materno'    => $v($p['apellido_materno'] ?? ''),
            'pais_nacionalidad'   => $v($p['nacionalidad'] ?? 'MX'),
            'actividad_economica' => ($v($p['actividad_economica'] ?? '') ?: '1000000'),
            'tipo_domicilio'      => $v($d['tipo_domicilio'] ?? '1'),

            'nac_entidad'   => $esExtranjero ? '' : $v($d['entidad_federativa'] ?? ''),
            'nac_calle'     => $esExtranjero ? '' : $v($d['calle'] ?? ''),
            'nac_num_ext'   => $esExtranjero ? '' : $v($d['num_ext'] ?? ''),
            'nac_num_int'   => $esExtranjero ? '' : $v($d['num_int'] ?? ''),
            'nac_correo'    => $esExtranjero ? '' : $v($d['correo'] ?? ''),
            'nac_cp'        => $esExtranjero ? '' : $v($d['codigo_postal'] ?? ''),
            'nac_colonia'   => $esExtranjero ? '' : $v($d['colonia'] ?? ''),
            'nac_municipio' => $esExtranjero ? '' : $v($d['municipio'] ?? ''),
            'nac_telefono'  => $esExtranjero ? '' : $v($d['telefono'] ?? ''),

            'ext_pais'      => $esExtranjero ? $v($d['pais'] ?? '') : '',
            'ext_estado'    => $esExtranjero ? $v($d['estado'] ?? '') : '',
            'ext_ciudad'    => $esExtranjero ? $v($d['ciudad'] ?? '') : '',
            'ext_calle'     => $esExtranjero ? $v($d['calle'] ?? '') : '',
            'ext_num_ext'   => $esExtranjero ? $v($d['num_ext'] ?? '') : '',
            'ext_num_int'   => $esExtranjero ? $v($d['num_int'] ?? '') : '',
            'ext_cp'        => $esExtranjero ? $v($d['codigo_postal'] ?? '') : '',
            'ext_colonia'   => $esExtranjero ? $v($d['colonia'] ?? '') : '',
            'ext_telefono'  => $esExtranjero ? $v($d['telefono'] ?? '') : '',
            'ext_correo'    => $esExtranjero ? $v($d['correo'] ?? '') : '',

            'op_guid'       => $opGuid,
        ];
    }

    private function fisicaColsEmpty(): array
    {
        $cols = $this->fisicaCols([], '', fn($x) => '', fn($x) => '');
        foreach ($cols as $k => $_) $cols[$k] = '';
        return $cols;
    }

    /** Moral person columns (930007/930012), manual order, verified vs real avisos. */
    private function moralCols(array $p, string $opGuid, string $personGuid, $v, $date): array
    {
        $d = $p['domicilio'] ?? [];
        $esExtranjero = ($v($d['tipo_domicilio'] ?? '') === '2');

        return [
            'rfc'                => $v($p['rfc'] ?? ''),
            'razon_social'       => $v($p['razon_social'] ?? ''),
            'fecha_constitucion' => $date($p['fecha_constitucion'] ?? ($p['fecha_nacimiento'] ?? null)),
            'pais_nacionalidad'  => $v($p['nacionalidad'] ?? 'MX'),
            'giro_mercantil'     => ($v($p['giro_mercantil'] ?? '') ?: '1000000'),
            'tipo_domicilio'     => $v($d['tipo_domicilio'] ?? '1'),

            'nac_entidad'   => $esExtranjero ? '' : $v($d['entidad_federativa'] ?? ''),
            'nac_calle'     => $esExtranjero ? '' : $v($d['calle'] ?? ''),
            'nac_num_ext'   => $esExtranjero ? '' : $v($d['num_ext'] ?? ''),
            'nac_num_int'   => $esExtranjero ? '' : $v($d['num_int'] ?? ''),
            'nac_correo'    => $esExtranjero ? '' : $v($d['correo'] ?? ''),
            'nac_cp'        => $esExtranjero ? '' : $v($d['codigo_postal'] ?? ''),
            'nac_colonia'   => $esExtranjero ? '' : $v($d['colonia'] ?? ''),
            'nac_municipio' => $esExtranjero ? '' : $v($d['municipio'] ?? ''),
            'nac_telefono'  => $esExtranjero ? '' : $v($d['telefono'] ?? ''),

            'ext_pais'      => $esExtranjero ? $v($d['pais'] ?? '') : '',
            'ext_estado'    => $esExtranjero ? $v($d['estado'] ?? '') : '',
            'ext_ciudad'    => $esExtranjero ? $v($d['ciudad'] ?? '') : '',
            'ext_calle'     => $esExtranjero ? $v($d['calle'] ?? '') : '',
            'ext_num_ext'   => $esExtranjero ? $v($d['num_ext'] ?? '') : '',
            'ext_num_int'   => $esExtranjero ? $v($d['num_int'] ?? '') : '',
            'ext_cp'        => $esExtranjero ? $v($d['codigo_postal'] ?? '') : '',
            'ext_colonia'   => $esExtranjero ? $v($d['colonia'] ?? '') : '',
            'ext_telefono'  => $esExtranjero ? $v($d['telefono'] ?? '') : '',
            'ext_correo'    => $esExtranjero ? $v($d['correo'] ?? '') : '',

            'op_guid'       => $opGuid,
            'person_guid'   => $personGuid,
        ];
    }

    private function moralColsEmpty(): array
    {
        $cols = $this->moralCols([], '', '', fn($x) => '', fn($x) => '');
        foreach ($cols as $k => $_) $cols[$k] = '';
        return $cols;
    }

    /** Representante legal columns (930008/930013). */
    private function repCols(array $rep, string $personGuid, $v, $date): array
    {
        return [
            'rfc'              => $v($rep['rfc'] ?? ''),
            'curp'             => $v($rep['curp'] ?? ''),
            'fecha_nacimiento' => $date($rep['fecha_nacimiento'] ?? null),
            'nombre'           => $v($rep['nombre'] ?? ''),
            'apellido_paterno' => $v($rep['apellido_paterno'] ?? ''),
            'apellido_materno' => $v($rep['apellido_materno'] ?? ''),
            'person_guid'      => $personGuid,
        ];
    }

    private function repColsEmpty(): array
    {
        $cols = $this->repCols([], '', fn($x) => '', fn($x) => '');
        foreach ($cols as $k => $_) $cols[$k] = '';
        return $cols;
    }

    private function emitPersonasFisicas(array &$lines, string $tag, array $personas, string $prefix, string $opGuid, int $seq, $v, $date): int
    {
        if (empty($personas)) {
            $lines[] = $tag . ':' . $this->row($this->fisicaColsEmpty());
            return $seq;
        }
        foreach ($personas as $p) {
            $lines[] = $tag . ':' . $this->row($this->fisicaCols($p, $opGuid, $v, $date));
        }
        return $seq;
    }

    private function emitPersonasMorales(array &$lines, string $tag, string $repTag, array $personas, string $prefix, string $opGuid, int $seq, $v, $date): int
    {
        if (empty($personas)) {
            $lines[] = $tag . ':' . $this->row($this->moralColsEmpty());
            $lines[] = $repTag . ':' . $this->row($this->repColsEmpty());
            return $seq;
        }
        foreach ($personas as $p) {
            $personGuid = $this->guid($prefix, 0, ++$seq);
            $lines[] = $tag . ':' . $this->row($this->moralCols($p, $opGuid, $personGuid, $v, $date));

            $rep = $p['representante'] ?? null;
            $lines[] = $repTag . ':' . $this->row(
                $rep ? $this->repCols($rep, $personGuid, $v, $date) : $this->repColsEmpty()
            );
        }
        return $seq;
    }

    /* ---------- helpers ---------- */

    private function guidPrefix(string $referencia): string
    {
        // Real SAT format: block1 = referencia * 100, zero-padded to 8 digits.
        //   ref 26640 -> 02664000, ref 26659 -> 02665900. (Verified vs real avisos.)
        $ref = (int) preg_replace('/\D/', '', $referencia);
        return sprintf('%08d', $ref * 100);
    }

    private function guid(string $prefix, int $thirdGroup, int $lastSeq): string
    {
        return sprintf('%s-0000-%04d-0000-%012d', $prefix, $thirdGroup, $lastSeq);
    }

    private function money($n): string
    {
        return number_format((float) $n, 2, '.', '');
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
            return 'unknown';
        }
        return 'unknown';
    }
}
