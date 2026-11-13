<?php

namespace Modules\FepPoder\V1;

/**
 * Otorgamiento de poder xlsm → review data. Cells per FedatarioPoder_v4_3 Sheet1.cls:
 *   poderdantes (full persona): rows 0..9, física B31+r, moral B44+r, fideicomiso G44+r.
 *   apoderados (simple persona + tipo_poder): rows 0..9, física B61+r, moral B74+r,
 *     fideicomiso G74+r; tipo_poder = clave1 of I(física)/F(moral)/J(fideicomiso).
 */
class XlsmReader extends \App\Services\Fep\FepXlsmReader
{
    public const TEMPLATE = 'FedatarioPoder';
    public const VERSION = '4_3';

    protected function readActivity(): array
    {
        $poderdantes = [];
        for ($r = 0; $r <= 9; $r++) {
            $p = $this->pickPersona(31 + $r, 44 + $r, 44 + $r, true);
            if ($p) $poderdantes[] = $p;
        }
        $apoderados = [];
        for ($r = 0; $r <= 9; $r++) {
            [$fis, $mor, $fid] = [61 + $r, 74 + $r, 74 + $r];
            if ($this->g("B{$fis}") !== '') {
                $apoderados[] = array_merge(['tipo_poder' => $this->clave1($this->g("I{$fis}"))], $this->personaFisica($fis, false));
            } elseif ($this->g("B{$mor}") !== '') {
                $apoderados[] = array_merge(['tipo_poder' => $this->clave1($this->g("F{$mor}"))], $this->personaMoral($mor, false));
            } elseif ($this->g("G{$fid}") !== '') {
                $apoderados[] = array_merge(['tipo_poder' => $this->clave1($this->g("J{$fid}"))], $this->fideicomiso($fid));
            }
        }
        return ['poderdantes' => $poderdantes, 'apoderados' => $apoderados];
    }
}
