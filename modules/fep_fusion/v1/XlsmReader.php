<?php

namespace Modules\FepFusion\V1;

/**
 * Fusión xlsm → review data. Cells per FedatarioFusion_v4_3 Sheet1.cls:
 *   C27 tipo_fusion(,) ·
 *   fusionadas rows 0..9 at B33+r: moral company block (no numero) ·
 *   C46 fusionante_determinadas (clave2 SI/NO); if SI →
 *     fusionante row 49: moral company block + numero_total_acciones (J49) +
 *     accionistas rows 0..9 (simple persona): física B55+r, moral B68+r, fideicomiso G68+r,
 *     numero_acciones = I(fís)/F(mor)/J(fid).
 */
class XlsmReader extends \App\Services\Fep\FepXlsmReader
{
    public const TEMPLATE = 'FedatarioFusion';
    public const VERSION = '4_3';

    protected function readActivity(): array
    {
        $fusionadas = [];
        for ($r = 0; $r <= 9; $r++) {
            if ($this->g('B' . (33 + $r)) !== '') $fusionadas[] = $this->moralCompany(33 + $r, false);
        }
        $determinadas = $this->clave2($this->g('C46'));
        $fusionante = null;
        if ($determinadas === 'SI') {
            $acc = [];
            for ($r = 0; $r <= 9; $r++) {
                [$fis, $mor, $fid] = [55 + $r, 68 + $r, 68 + $r];
                if ($this->g("B{$fis}") !== '') {
                    $acc[] = array_merge($this->personaFisica($fis, false), ['numero_acciones' => $this->g("I{$fis}")]);
                } elseif ($this->g("B{$mor}") !== '') {
                    $acc[] = array_merge($this->personaMoral($mor, false), ['numero_acciones' => $this->g("F{$mor}")]);
                } elseif ($this->g("G{$fid}") !== '') {
                    $acc[] = array_merge($this->fideicomiso($fid), ['numero_acciones' => $this->g("J{$fid}")]);
                }
            }
            $fusionante = array_merge($this->moralCompany(49, true), ['accionistas' => $acc]);
        }
        return [
            'tipo_fusion' => $this->clave1($this->g('C27')),
            'fusionadas' => $fusionadas,
            'fusionante_determinadas' => $determinadas,
            'fusionante' => $fusionante,
        ];
    }
}
