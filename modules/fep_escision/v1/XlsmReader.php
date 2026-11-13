<?php

namespace Modules\FepEscision\V1;

/**
 * Escisión xlsm → review data. Cells per FedatarioEscision_v4_3 Sheet1.cls:
 *   escindente: moral company block row 30 + escindente_subsiste clave2(D32) +
 *     accionistas (if SI) rows 0..9: física B38+r (numero I), moral B51+r (numero F),
 *     fideicomiso G51+r (numero J) — flat list, simple personas.
 *   escindidas_determinadas clave2(D64); dato_escindida rows i=0..9 at B67+i:
 *     moral company block (+ numero_total_acciones J) + accionistas matched to this
 *     escindida by name across rows j=0..9: física B82+j (owner J, numero I),
 *     moral B95+j (owner G, numero F), fideicomiso H95+j (owner L, numero K; cols H/I/J).
 *
 * NOTE: the escindida→accionista association is by company name (col J/G/L == the
 * escindida's denominación), matching SAT's macro. Verified here for the common
 * single-escindida case; multi-escindida distribution needs a real filled .xlsm.
 */
class XlsmReader extends \App\Services\Fep\FepXlsmReader
{
    public const TEMPLATE = 'FedatarioEscision';
    public const VERSION = '4_3';

    protected function readActivity(): array
    {
        // Escindente + its (flat) accionistas
        $escindente = $this->moralCompany(30, false);
        $escindente['escindente_subsiste'] = $this->clave2($this->g('D32'));
        $escAcc = [];
        if ($escindente['escindente_subsiste'] === 'SI') {
            for ($r = 0; $r <= 9; $r++) {
                [$fis, $mor, $fid] = [38 + $r, 51 + $r, 51 + $r];
                if ($this->g("B{$fis}") !== '') {
                    $escAcc[] = array_merge($this->personaFisica($fis, false), ['numero_acciones' => $this->g("I{$fis}")]);
                } elseif ($this->g("B{$mor}") !== '') {
                    $escAcc[] = array_merge($this->personaMoral($mor, false), ['numero_acciones' => $this->g("F{$mor}")]);
                } elseif ($this->g("G{$fid}") !== '') {
                    $escAcc[] = array_merge($this->fideicomiso($fid), ['numero_acciones' => $this->g("J{$fid}")]);
                }
            }
        }
        $escindente['accionistas'] = $escAcc;

        // Escindidas + accionistas matched by company name
        $determinadas = $this->clave2($this->g('D64'));
        $escindidas = [];
        if ($determinadas === 'SI') {
            for ($i = 0; $i <= 9; $i++) {
                $er = 67 + $i;
                if ($this->g("B{$er}") === '') continue;
                $es = $this->moralCompany($er, true);
                $name = $this->g("B{$er}");
                $acc = [];
                for ($j = 0; $j <= 9; $j++) {
                    [$fis, $mor, $fid] = [82 + $j, 95 + $j, 95 + $j];
                    if ($this->g("B{$fis}") !== '' && $this->g("J{$fis}") === $name) {
                        $acc[] = array_merge($this->personaFisica($fis, false), ['numero_acciones' => $this->g("I{$fis}")]);
                    } elseif ($this->g("B{$mor}") !== '' && $this->g("G{$mor}") === $name) {
                        $acc[] = array_merge($this->personaMoral($mor, false), ['numero_acciones' => $this->g("F{$mor}")]);
                    } elseif ($this->g("H{$fid}") !== '' && $this->g("L{$fid}") === $name) {
                        $acc[] = array_merge($this->fideicomisoEsc($fid), ['numero_acciones' => $this->g("K{$fid}")]);
                    }
                }
                $es['accionistas'] = $acc;
                $escindidas[] = $es;
            }
        }

        return [
            'escindente' => $escindente,
            'escindidas_determinadas' => $determinadas,
            'escindidas' => $escindidas,
        ];
    }
}
