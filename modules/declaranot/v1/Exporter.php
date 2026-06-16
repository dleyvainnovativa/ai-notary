<?php

namespace Modules\Declaranot\V1;

use App\Modules\ExporterContract;
use Carbon\Carbon;

class Exporter implements ExporterContract
{
    public function supportedFormats(): array
    {
        return ['txt'];
    }

    public function export(array $validatedData, string $format): string
    {
        if ($format !== 'txt') {
            throw new \InvalidArgumentException('Declaranot solo soporta TXT.');
        }
        return $this->generateDeclaranotTXT($validatedData);
    }

    /**
     * Merge sectioned, validated data into one flat array.
     * Accepts either ['escritura'=>[...], 'calculo'=>[...]] or an already-flat array.
     */
    private function flatten(array $data): array
    {
        if (isset($data['escritura']) || isset($data['calculo'])) {
            return array_merge($data['escritura'] ?? [], $data['calculo'] ?? []);
        }
        return $data; // already flat
    }

    private function generateDeclaranotTXT(array $json): string
    {
        $lines = [];
        $v = fn($value) => $value ?? '';
        $date = function ($value) {
            if (!$value) return '';
            return \Carbon\Carbon::parse($value)->format('d/m/Y');
        };
        $lines[] =
            'Configuracion:2025|001|035|24|' .
            $date($json['fecha_firma_escritura']);

        // ── Line: 900001 — Datos de la operación ──────────────────────────────────
        // tipo_inmueble is already a numeric string (1–9) from the new schema
        $lines[] =
            '900001-DatosOperacion:' .
            $v($json['numero_escritura'])       . '|' .
            $date($json['fecha_firma_escritura']) . '|' .
            $v($json['tipo_inmueble'])          . '|' .   // e.g. "4" = Terreno
            $v($json['especifica_inmueble'])    . '|' .
            $v($json['avaluo_inmueble']);

        // ── Lines: 900002 — Pagos del inmueble (grid) ─────────────────────────────
        // tipo is already a numeric string (1–4); monto rounded to integer
        // institucion_financiera and numero_cuenta added; index 1-based
        foreach (($json['pagos_inmueble'] ?? []) as $index => $pago) {
            $lines[] =
                '900002-DatosPago-grid:' .
                round($v($pago['monto']))               . '|' .
                $v($pago['tipo_pago_inmueble'])                        . '|' .   // "1"=Efectivo,"2"=Cheque,"3"=Transferencia,"9"=Otro
                $v($pago['institucion_financiera'])      . '|' .
                // ($index + 1)                             . '|' .
                $v($pago['numero_cuenta'])  . '|' .
                $v($pago['otro_pago']);
        }

        // ── Lines: 900003 — Enajenantes (grid) ────────────────────────────────────
        // tipo is already "1" (nacional) or "2" (extranjero)
        foreach (($json['enajenantes'] ?? []) as $enajenante) {
            $lines[] =
                '900003-DatosEnajenante-grid:' .
                $v($enajenante['tipo_enajenante'])              . '|' .
                $v($enajenante['rfc'])               . '|' .
                $v($enajenante['nombre'])            . '|' .
                $v($enajenante['apellido_paterno'])  . '|' .
                $v($enajenante['apellido_materno'])  . '|' .
                $v($enajenante['curp'])              . '|' .
                $v($enajenante['razon_social'])              . '|' .
                $v($enajenante['nacionalidad'])              . '|' .
                $v($enajenante['fecha_nacimiento'])              . '|' .
                $v($enajenante['documento_oficial'])              . '|' .
                $v($enajenante['folio']);
        }

        // ── Line: 900004 — Datos informativos ─────────────────────────────────────
        // datos_informativos is now a plain object (not an array)
        // ingresos_exentos: "1"=Sí, "2"=No
        $info = $json['datos_informativos'] ?? null;
        if ($info && isset($info['ingresos_exentos'])) {
            $lines[] =
                '900004-DatosInformativos:' .
                $v($info['ingresos_exentos']) . '|' .   // "1" or "2"
                $v($info['monto'])            . '|' .
                $v($info['impuesto']);
        } else {
            $lines[] = '900004-DatosInformativos:2||';
        }

        // ── Lines: 900005 — Adquirientes (grid) ───────────────────────────────────
        foreach (($json['adquirientes'] ?? []) as $adquiriente) {
            $lines[] =
                '900005-DatosAdquiriente-grid:' .
                $v($adquiriente['tipo_adquiriente']) . '|' .
                $v($adquiriente['rfc'])              . '|' .
                $v($adquiriente['nombre'])           . '|' .
                $v($adquiriente['apellido_paterno']) . '|' .
                $v($adquiriente['apellido_materno']) . '|' .
                $v($adquiriente['curp'])             . '|' .
                $v($adquiriente['razon_social'])      . '|' .
                $v($adquiriente['nacionalidad'])      . '|' .
                $v($adquiriente['fecha_nacimiento'])  . '|' .
                $v($adquiriente['documento_oficial']) . '|' .
                $v($adquiriente['folio']);
        }

        // ── Lines: 900006 — Datos de pago / ISR (grid) ───────────────────────────
        foreach (($json['pago'] ?? []) as $pago) {
            $lines[] =
                '900006-DatosPago-grid:' .
                $v($pago['ingresos_enajenacion'])        . '|' .
                $v($pago['ingresos_exentos'])            . '|' .
                $v($pago['ingreso_sismo_2017'])          . '|' .
                $v($pago['deducciones_autorizadas'])     . '|' .
                $v($pago['ganancia_perdida'])            . '|' .
                $v($pago['years_adquisicion_venta'])     . '|' .
                $v($pago['ganancia_acumulable'])         . '|' .
                $v($pago['ganancia_no_acumulable'])      . '|' .
                $v($pago['isr_federacion'])              . '|' .
                $v($pago['numero_operacion_federacion']) . '|' .
                $date($pago['fecha_pago_federacion'])    . '|' .
                $v($pago['isr_entidad'])                 . '|' .
                $v($pago['numero_operacion_entidad'])    . '|' .
                $date($pago['fecha_pago_entidad'])       . '|' .
                $v($pago['total_isr_pagado']);
        }

        // ── Lines: 900010 / 900013 / 900009 / 900007 / 900011 — Copropiedad ───────
        // existe_copropiedad is now a top-level scalar ("1"=Sí, "2"=No)
        // copropiedad is now an object with integrantes[] nested inside
        // representante_comun is now its own top-level object

        $copropiedadObj     = $json['copropiedad']        ?? null;
        $existeCopropiedad  = $copropiedadObj['existe_copropiedad'] ?? '2';
        $integrantes        = $copropiedadObj['integrantes'] ?? [];
        $representanteObj   = $json['representante_comun'] ?? null;

        $lines[] =
            '900010-IngresoCopropiedadOSucesion:' .
            $v($existeCopropiedad);                          // "1" or "2"

        $existeRepresentante = $representanteObj['existe_representante_comun'] ?? '2';
        $rfcRepresentante    = $representanteObj['rfc_representante'] ?? null;

        $lines[] =
            '900013-PreguntaExisteRepresentanteLegal:' .
            $v($existeRepresentante);                        // "1" or "2"

        $lines[] =
            '900009-RepresentanteLegal:' .
            $v($rfcRepresentante);

        if (!empty($integrantes)) {
            $totalPorcentaje = 0;
            foreach ($integrantes as $integrante) {
                $totalPorcentaje += (float) ($integrante['porcentaje'] ?? 0);
                $lines[] =
                    '900007-DatosCopropiedad-grid:' .
                    $v($integrante['rfc'])                   . '|' .
                    $v($integrante['porcentaje'])            . '|' .
                    $v($integrante['ingresos_enajenacion'])  . '|' .
                    $v($integrante['deducciones_autorizadas']) . '|' .
                    $v($integrante['ganancia_perdida'])      . '|' .
                    $v($integrante['ganancia_acumulable'])   . '|' .
                    $v($integrante['ganancia_no_acumulable']) . '|' .
                    $v($integrante['isr_federacion'])        . '|' .
                    $v($integrante['isr_entidad']);
            }
            $lines[] = '900011-TotalPorcentajeCopropiedad:' . $totalPorcentaje;
        } else {
            $lines[] = '900007-DatosCopropiedad-grid:||||||||';
            $lines[] = '900011-TotalPorcentajeCopropiedad:';
        }

        return implode("\n", $lines);
    }
}
