<?php

namespace Modules\FepConstitucionpm\V1;

/**
 * Constitución de PM xlsm → review data. Cells per FedatarioConstitucionPM_v4_4 Sheet1.cls:
 *   C29 tipo_persona_moral · E29 otra · B32 denominacion · C32 giro(||) · D32 folio ·
 *   E32 numero_total_acciones · F32 entidad(,) · G32 consejo_vigilancia(SI/NO) ·
 *   H32 motivo(,) · I32 instrumento ·
 *   accionistas rows 0..9: física B39+r, moral B52+r, fideicomiso B65+r (simple persona);
 *     cargo = clave1 of J(fís)/G(mor)/F(fid); numero_acciones = I(fís)/F(mor)/E(fid) ·
 *   capital_social: C79 fijo, F79 variable.
 */
class XlsmReader extends \App\Services\Fep\FepXlsmReader
{
    public const TEMPLATE = 'FedatarioConstitucionPM';
    public const VERSION = '4_4';

    protected function readActivity(): array
    {
        $accionistas = [];
        for ($r = 0; $r <= 9; $r++) {
            [$fis, $mor, $fid] = [39 + $r, 52 + $r, 65 + $r];
            if ($this->g("B{$fis}") !== '') {
                $accionistas[] = array_merge(
                    ['cargo_accionista' => $this->clave1($this->g("J{$fis}"))],
                    $this->personaFisica($fis, false),
                    ['numero_acciones' => $this->g("I{$fis}")]
                );
            } elseif ($this->g("B{$mor}") !== '') {
                $accionistas[] = array_merge(
                    ['cargo_accionista' => $this->clave1($this->g("G{$mor}"))],
                    $this->personaMoral($mor, false),
                    ['numero_acciones' => $this->g("F{$mor}")]
                );
            } elseif ($this->g("B{$fid}") !== '') {
                $accionistas[] = array_merge(
                    ['cargo_accionista' => $this->clave1($this->g("F{$fid}"))],
                    $this->fideicomiso($fid),
                    ['numero_acciones' => $this->g("E{$fid}")]
                );
            }
        }
        return [
            'tipo_persona_moral' => $this->clave1($this->g('C29')),
            'tipo_persona_moral_otra' => $this->g('E29'),
            'denominacion_razon' => $this->g('B32'),
            'giro_mercantil' => $this->clave3($this->g('C32')),
            'folio_mercantil' => $this->g('D32'),
            'numero_total_acciones' => $this->g('E32'),
            'entidad_federativa' => $this->clave1($this->g('F32')),
            'consejo_vigilancia' => $this->g('G32'),
            'motivo_constitucion' => $this->clave1($this->g('H32')),
            'instrumento_publico' => $this->g('I32'),
            'accionistas' => $accionistas,
            'capital_social' => [
                'capital_fijo' => $this->g('C79'),
                'capital_variable' => $this->g('F79'),
            ],
        ];
    }
}
