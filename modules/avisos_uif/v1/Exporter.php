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
        $lines[] = '930002-Datos del Aviso:' . $referencia . '|' .
            $v($json['prioridad'] ?? '') . '|' .
            $v($json['tipo_alerta'] ?? '') . '|' .
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
            $lines[] = '930009-Datos comprador fideicomiso-grid:' . str_repeat('|', 23);
            $lines[] = '930010-Representante legal realiza operacion a nombre fideicomiso-grid:' . str_repeat('|', 5);

            // Split vendedores by persona_case
            [$vendFisicas, $vendMorales] = $this->splitByCase($op['vendedores'] ?? []);
            $personGuidSeq = $this->emitPersonasFisicas($lines, '930011-Datos vendedor Persona Fisica-grid', $vendFisicas, $guidPrefix, $opGuid, $personGuidSeq, $v, $date);
            $personGuidSeq = $this->emitPersonasMorales($lines, '930012-Datos vendedor Persona Moral-grid', '930013-Representante legal realiza operacion a nombre persona moral-grid', $vendMorales, $guidPrefix, $opGuid, $personGuidSeq, $v, $date);

            $lines[] = '930014-Datos vendedor fideicomiso-grid:' . str_repeat('|', 23);
            $lines[] = '930015-Representante legal realiza operacion a nombre fideicomiso-grid:' . str_repeat('|', 5);

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

    /* ---------- persona emission ---------- */

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

    private function emitPersonasFisicas(array &$lines, string $tag, array $personas, string $prefix, string $opGuid, int $seq, $v, $date): int
    {
        if (empty($personas)) {
            $lines[] = $tag . ':' . str_repeat('|', 27); // empty física line (28 cols)
            return $seq;
        }
        foreach ($personas as $p) {
            $d = $p['domicilio'] ?? [];
            $lines[] = $tag . ':' .
                $v($p['rfc'] ?? '') . '|' . $v($p['curp'] ?? '') . '|' . $date($p['fecha_nacimiento'] ?? null) . '|' .
                $v($p['nombre'] ?? '') . '|' . $v($p['apellido_paterno'] ?? '') . '|' . $v($p['apellido_materno'] ?? '') . '|' .
                $v($p['nacionalidad'] ?? 'MX') . '|' .
                '1000000' . '|' .   // placeholder column seen in examples (actividad?) — CONFIRM
                $v($d['tipo_domicilio'] ?? '1') . '|' .
                $v($d['entidad_federativa'] ?? '') . '|' .
                $v($d['calle'] ?? '') . '|' .
                $v($d['num_ext'] ?? '') . '|' .
                $v($d['num_int'] ?? '') . '|' .
                '|' .
                $v($d['codigo_postal'] ?? '') . '|' .
                $v($d['colonia'] ?? '') . '|' .
                $v($d['municipio'] ?? '') . '|' .
                str_repeat('|', 10) .  // trailing empty cols before GUID (examples show many)
                $opGuid;
        }
        return $seq;
    }

    private function emitPersonasMorales(array &$lines, string $tag, string $repTag, array $personas, string $prefix, string $opGuid, int $seq, $v, $date): int
    {
        if (empty($personas)) {
            $lines[] = $tag . ':' . str_repeat('|', 25);
            $lines[] = $repTag . ':' . str_repeat('|', 6);
            return $seq;
        }
        foreach ($personas as $p) {
            $d = $p['domicilio'] ?? [];
            $personGuid = $this->guid($prefix, 0, ++$seq);
            $lines[] = $tag . ':' .
                $v($p['rfc'] ?? '') . '|' . $v($p['razon_social'] ?? '') . '|' . $date($p['fecha_nacimiento'] ?? null) . '|' .
                $v($p['nacionalidad'] ?? 'MX') . '|' .
                '4340006' . '|' .   // placeholder seen in examples — CONFIRM
                $v($d['tipo_domicilio'] ?? '1') . '|' .
                $v($d['entidad_federativa'] ?? '') . '|' .
                $v($d['calle'] ?? '') . '|' .
                $v($d['num_ext'] ?? '') . '|' .
                $v($d['num_int'] ?? '') . '|' .
                '|' .
                $v($d['codigo_postal'] ?? '') . '|' .
                $v($d['colonia'] ?? '') . '|' .
                $v($d['municipio'] ?? '') . '|' .
                '|' . $v($p['nacionalidad'] ?? 'MX') . '|' .
                str_repeat('|', 9) .
                $opGuid . '|' . $personGuid;

            // 930008/930013 — representante legal for this moral
            $rep = $p['representante'] ?? null;
            if ($rep) {
                $lines[] = $repTag . ':' .
                    $v($rep['rfc'] ?? '') . '|' . $v($rep['curp'] ?? '') . '|' . $date($rep['fecha_nacimiento'] ?? null) . '|' .
                    $v($rep['nombre'] ?? '') . '|' . $v($rep['apellido_paterno'] ?? '') . '|' . $v($rep['apellido_materno'] ?? '') . '|' .
                    $personGuid;
            } else {
                $lines[] = $repTag . ':' . str_repeat('|', 6);
            }
        }
        return $seq;
    }

    /* ---------- helpers ---------- */

    private function guidPrefix(string $referencia): string
    {
        // 26600 → "02660000"  (02 + referencia + trailing to 8 chars)
        $ref = preg_replace('/\D/', '', $referencia);
        return '02' . str_pad($ref, 4, '0', STR_PAD_LEFT) . '00';
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
