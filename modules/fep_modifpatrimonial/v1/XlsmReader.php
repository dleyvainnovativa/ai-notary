<?php

namespace Modules\FepModifpatrimonial\V1;

/**
 * Modificación patrimonial xlsm → review data. Cells per FedatarioModifPatrimonial_v4_4:
 *   persona_moral_modifica row 30: B denominacion · C fecha_const · D rfc · E pais(,) ·
 *     F giro(||) · G numero_total_acciones · H motivo(,) · I instrumento ·
 *   datos_modificacion row 35: B tipo_mod_cap_fijo(,) · C inicial_fijo · D final_fijo ·
 *     E tipo_mod_cap_var(,) · F inicial_var · G final_var ·
 *   accionistas rows 0..9: física B41+r, moral B54+r, fideicomiso G54+r (simple persona);
 *     numero_acciones = I(fís)/F(mor)/J(fid).
 */
class XlsmReader extends \App\Services\Fep\FepXlsmReader
{
    public const TEMPLATE = 'FedatarioModifPatrimonial';
    public const VERSION = '4_4';

    protected function readActivity(): array
    {
        $accionistas = [];
        for ($r = 0; $r <= 9; $r++) {
            [$fis, $mor, $fid] = [41 + $r, 54 + $r, 54 + $r];
            if ($this->g("B{$fis}") !== '') {
                $accionistas[] = array_merge($this->personaFisica($fis, false), ['numero_acciones' => $this->g("I{$fis}")]);
            } elseif ($this->g("B{$mor}") !== '') {
                $accionistas[] = array_merge($this->personaMoral($mor, false), ['numero_acciones' => $this->g("F{$mor}")]);
            } elseif ($this->g("G{$fid}") !== '') {
                $accionistas[] = array_merge($this->fideicomiso($fid), ['numero_acciones' => $this->g("J{$fid}")]);
            }
        }
        return [
            'persona_moral_modifica' => [
                'denominacion_razon' => $this->g('B30'),
                'fecha_constitucion' => $this->g('C30'),
                'rfc' => $this->g('D30'),
                'pais_nacionalidad' => $this->clave2($this->g('E30')),
                'giro_mercantil' => $this->clave3($this->g('F30')),
                'numero_total_acciones' => $this->g('G30'),
                'motivo_modificacion' => $this->clave1($this->g('H30')),
                'instrumento_publico' => $this->g('I30'),
            ],
            'datos_modificacion' => [
                'tipo_modificacion_capital_fijo' => $this->clave1($this->g('B35')),
                'inicial_capital_fijo' => $this->g('C35'),
                'final_capital_fijo' => $this->g('D35'),
                'tipo_modificacion_capital_variable' => $this->clave1($this->g('E35')),
                'inicial_capital_variable' => $this->g('F35'),
                'final_capital_variable' => $this->g('G35'),
                'accionistas' => $accionistas,
            ],
        ];
    }
}
