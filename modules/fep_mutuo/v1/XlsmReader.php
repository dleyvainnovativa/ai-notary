<?php

namespace Modules\FepMutuo\V1;

/**
 * Mutuo / crédito xlsm → review data. Cells per FedatarioMutuo_v4_4 Sheet1.cls:
 *   C26 tipo_otorgamiento(,) ·
 *   acreedores rows 0..9 (full persona): física B33+r, moral B46+r, fideicomiso G46+r ·
 *   deudores  rows 0..9 (full persona): física B63+r, moral B76+r, fideicomiso G76+r ·
 *   garantías (only when tipo_otorgamiento=2) rows 0..9 at B90+r: tipo_garantia(,);
 *     "2,Inmueble" → datos_inmueble (C tipo_inmueble, D valor_referencia, E cp, F folio);
 *     "99,..."     → datos_otro (G descripcion_garantia);
 *     garante (no país) rows: física B106+r, moral B119+r, fideicomiso G119+r ·
 *   liquidaciones rows 0..9 at B132+r: B moneda(,), C monto_operacion.
 */
class XlsmReader extends \App\Services\Fep\FepXlsmReader
{
    public const TEMPLATE = 'FedatarioMutuo';
    public const VERSION = '4_4';

    protected function readActivity(): array
    {
        $acreedores = $this->parties(33, 46);
        $deudores = $this->parties(63, 76);

        $tipoOtorg = $this->clave1($this->g('C26'));
        $garantias = [];
        if ($tipoOtorg === '2') {
            for ($r = 0; $r <= 9; $r++) {
                $gr = 90 + $r;
                if ($this->g("B{$gr}") === '') continue;
                $tipoG = $this->g("B{$gr}");
                $g = ['tipo_garantia' => $this->clave1($tipoG), 'clase_bien' => '', 'garante' => []];
                if (str_starts_with($tipoG, '2,')) {
                    $g = array_merge($g, [
                        'clase_bien' => 'inmueble',
                        'tipo_inmueble' => $this->clave1($this->g("C{$gr}")),
                        'valor_referencia' => $this->g("D{$gr}"),
                        'codigo_postal' => $this->g("E{$gr}"),
                        'folio_real' => $this->g("F{$gr}"),
                    ]);
                } elseif (str_starts_with($tipoG, '99,')) {
                    $g = array_merge($g, ['clase_bien' => 'otro', 'descripcion_garantia' => $this->g("G{$gr}")]);
                }
                // garante (persona_fisica_garante has no país/actividad — exporter drops extras)
                $g['garante'] = $this->pickPersona(106 + $r, 119 + $r, 119 + $r, false);
                $garantias[] = $g;
            }
        }

        $liquidaciones = [];
        for ($r = 0; $r <= 9; $r++) {
            $lr = 132 + $r;
            if ($this->g("B{$lr}") === '') continue;
            $liquidaciones[] = ['moneda' => $this->clave1($this->g("B{$lr}")), 'monto_operacion' => $this->g("C{$lr}")];
        }

        return [
            'tipo_otorgamiento' => $tipoOtorg,
            'acreedores' => $acreedores,
            'deudores' => $deudores,
            'garantias' => $garantias,
            'liquidaciones' => $liquidaciones,
        ];
    }

    /** Full personas across rows 0..9 for a física/moral band (fideicomiso shares the moral row). */
    private function parties(int $fisBase, int $moralBase): array
    {
        $out = [];
        for ($r = 0; $r <= 9; $r++) {
            $p = $this->pickPersona($fisBase + $r, $moralBase + $r, $moralBase + $r, true);
            if ($p) $out[] = $p;
        }
        return $out;
    }
}
