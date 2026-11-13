<?php

namespace App\Services\Fep;

/**
 * Reads a filled official SAT FEP template (the "Persona Objeto del aviso"
 * sheet) into the canonical review-data array — the same shape the module
 * Exporter consumes. This replaces SAT's VBA macro: the cell→field map here is
 * transcribed from each template's Sheet1.cls `xml()` routine (the ground truth).
 *
 * The envelope (sujeto_obligado, aviso header, persona_aviso, datos_operacion)
 * lives at identical cells across all 10 templates, so the base reads it; each
 * module's XlsmReader implements readActivity() for its <tipo_actividad> block.
 *
 * Cell reads go through a FepXlsmCellSource so the logic is testable without
 * PhpSpreadsheet. Transforms mirror the VBA helpers:
 *   clave1("1,NORMAL") = "1"   ·   clave2("AFGANISTAN,AF") = "AF"
 */
abstract class FepXlsmReader
{
    public function __construct(protected FepXlsmCellSource $c) {}

    /** Code before the first comma (selects stored as "code,label"). */
    protected function clave1(string $v): string
    {
        $v = trim($v);
        $p = strpos($v, ',');
        return $p === false ? $v : trim(substr($v, 0, $p));
    }

    /** Code after the first comma (país stored as "NAME,CODE"). */
    protected function clave2(string $v): string
    {
        $v = trim($v);
        $p = strpos($v, ',');
        return $p === false ? $v : trim(substr($v, $p + 1));
    }

    /** Code after "||" (actividad económica / giro stored as "LABEL||CODE"). */
    protected function clave3(string $v): string
    {
        $v = trim($v);
        $p = strpos($v, '||');
        return $p === false ? $v : trim(substr($v, $p + 2));
    }

    protected function g(string $addr): string
    {
        return $this->c->get($addr);
    }

    /** True if any of the given cells holds a value (anchor test for repeat rows). */
    protected function any(string ...$addrs): bool
    {
        foreach ($addrs as $a) {
            if ($this->g($a) !== '') return true;
        }
        return false;
    }

    /** Each module reads its own <tipo_actividad> block into the 'actividad' array. */
    abstract protected function readActivity(): array;

    /** Shared envelope + one operation wrapping the module's activity. */
    public function read(): array
    {
        return [
            'mes_reportado' => $this->g('C5'),
            'sujeto_obligado' => [
                'clave_sujeto_obligado' => $this->g('C4'),
                'clave_entidad_colegiada' => $this->g('C9'),
            ],
            'referencia_aviso' => $this->g('C6'),
            'prioridad' => $this->clave1($this->g('E6')),
            'modificatorio' => $this->g('C12') !== '' ? [
                'folio_modificacion' => $this->g('C12'),
                'descripcion_modificacion' => $this->g('C13'),
            ] : null,
            'alerta' => [
                'tipo_alerta' => $this->clave1($this->g('C7')),
                'descripcion_alerta' => $this->g('E7'),
            ],
            'personas_aviso' => [[
                'nombre' => $this->g('B19'),
                'apellido_paterno' => $this->g('C19'),
                'apellido_materno' => $this->g('D19'),
                'fecha_nacimiento' => $this->g('E19'),
                'rfc' => $this->g('F19'),
                'curp' => $this->g('G19'),
            ]],
            'operaciones' => [[
                'instrumento_publico' => $this->g('C23'),
                'fecha_operacion' => $this->g('C24'),
                'actividad' => $this->readActivity(),
            ]],
        ];
    }

    /**
     * A persona física block at row $r (cols B..H), VBA xmlPersonaFisica.
     * variant 'full' includes actividad_economica (col I in templates that carry it).
     */
    protected function personaFisica(int $r, bool $withActividad = false): array
    {
        $p = [
            'tipo' => 'fisica',
            'nombre' => $this->g("B{$r}"),
            'apellido_paterno' => $this->g("C{$r}"),
            'apellido_materno' => $this->g("D{$r}"),
            'fecha_nacimiento' => $this->g("E{$r}"),
            'rfc' => $this->g("F{$r}"),
            'curp' => $this->g("G{$r}"),
            'pais_nacionalidad' => $this->clave2($this->g("H{$r}")),
        ];
        if ($withActividad) $p['actividad_economica'] = $this->clave3($this->g("I{$r}"));
        return $p;
    }

    /** Persona moral at row $r (cols B..F), VBA xmlPersonaMoral. */
    protected function personaMoral(int $r, bool $withGiro = false): array
    {
        $p = [
            'tipo' => 'moral',
            'denominacion_razon' => $this->g("B{$r}"),
            'fecha_constitucion' => $this->g("C{$r}"),
            'rfc' => $this->g("D{$r}"),
            'pais_nacionalidad' => $this->clave2($this->g("E{$r}")),
        ];
        if ($withGiro) $p['giro_mercantil'] = $this->clave3($this->g("F{$r}"));
        return $p;
    }

    /**
     * Pick the person captured across the física/moral/fideicomiso row band:
     * física anchors on B$fisRow, moral on B$moralRow, fideicomiso on G$fideRow.
     * Returns [] when none is filled.
     */
    protected function pickPersona(int $fisRow, int $moralRow, int $fideRow, bool $full = true): array
    {
        if ($this->g("B{$fisRow}") !== '') return $this->personaFisica($fisRow, $full);
        if ($this->g("B{$moralRow}") !== '') return $this->personaMoral($moralRow, $full);
        if ($this->g("G{$fideRow}") !== '') return $this->fideicomiso($fideRow);
        return [];
    }

    /**
     * A persona-moral "company" block (fusionada/fusionante/escindente/escindida):
     * B denominacion · C fecha_constitucion · D rfc · E pais · F giro ·
     * G capital_social_fijo · H capital_social_variable · I folio_mercantil ·
     * J numero_total_acciones (only when $withNum). VBA xmlPersonaMoralFusion[2].
     */
    protected function moralCompany(int $r, bool $withNum = false): array
    {
        $m = [
            'denominacion_razon' => $this->g("B{$r}"),
            'fecha_constitucion' => $this->g("C{$r}"),
            'rfc' => $this->g("D{$r}"),
            'pais_nacionalidad' => $this->clave2($this->g("E{$r}")),
            'giro_mercantil' => $this->clave3($this->g("F{$r}")),
            'capital_social_fijo' => $this->g("G{$r}"),
            'capital_social_variable' => $this->g("H{$r}"),
            'folio_mercantil' => $this->g("I{$r}"),
        ];
        if ($withNum) $m['numero_total_acciones'] = $this->g("J{$r}");
        return $m;
    }

    /**
     * Fideicomiso in the "escindida/compraventa" band, shifted one column right:
     * VBA xmlPersonaFideicomisoEsc reads H(denom)/I(rfc)/J(identificador).
     */
    protected function fideicomisoEsc(int $r): array
    {
        return [
            'tipo' => 'fideicomiso',
            'denominacion_razon' => $this->g("H{$r}"),
            'rfc' => $this->g("I{$r}"),
            'identificador_fideicomiso' => $this->g("J{$r}"),
        ];
    }

    /** Fideicomiso at row $r (cols G..I), VBA xmlPersonaFideicomiso. */
    protected function fideicomiso(int $r): array
    {
        return [
            'tipo' => 'fideicomiso',
            'denominacion_razon' => $this->g("G{$r}"),
            'rfc' => $this->g("H{$r}"),
            'identificador_fideicomiso' => $this->g("I{$r}"),
        ];
    }
}
