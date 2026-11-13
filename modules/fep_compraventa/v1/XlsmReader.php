<?php

namespace Modules\FepCompraventa\V1;

/**
 * Compraventa de acciones xlsm → review data. Cells per FedatarioCompraVenta_v4_3:
 *   C27 tipo_operacion(,) ·
 *   sociedades rows r=0..9 at B31+r: B denominacion · C fecha_const · D rfc · E pais(,) ·
 *     F valor_nominal · G numero_acciones ·
 *     vendedores matched by name across j=0..9: física B47+j (owner J, numero I),
 *       moral B60+j (owner G, numero F), fideicomiso H60+j (owner L, numero K) ·
 *     compradores matched by name: física B75+j (owner J, numero I),
 *       moral B88+j (owner G, numero F), fideicomiso H88+j (owner L, numero K) ·
 *   liquidaciones rows r=0..9 at B102+r: B fecha_pago · D instrumento_monetario(,) ·
 *     E moneda(,) · F monto_operacion.  (VBA also writes forma_pago from col C, but the
 *     XSD + examples have no forma_pago in datos_liquidacion, so it is intentionally skipped.)
 *
 * NOTE: vendedor/comprador → sociedad association is by company name, matching SAT's
 * macro. Verified for the single-sociedad case; multi-sociedad needs a real filled .xlsm.
 */
class XlsmReader extends \App\Services\Fep\FepXlsmReader
{
    public const TEMPLATE = 'FedatarioCompraVenta';
    public const VERSION = '4_3';

    protected function readActivity(): array
    {
        $sociedades = [];
        for ($r = 0; $r <= 9; $r++) {
            $sr = 31 + $r;
            if ($this->g("B{$sr}") === '') continue;
            $name = $this->g("B{$sr}");
            $sociedades[] = [
                'denominacion_razon' => $name,
                'fecha_constitucion' => $this->g("C{$sr}"),
                'rfc' => $this->g("D{$sr}"),
                'pais_nacionalidad' => $this->clave2($this->g("E{$sr}")),
                'valor_nominal' => $this->g("F{$sr}"),
                'numero_acciones' => $this->g("G{$sr}"),
                'vendedores' => $this->parts($name, 47, 60),
                'compradores' => $this->parts($name, 75, 88),
            ];
        }

        $liquidaciones = [];
        for ($r = 0; $r <= 9; $r++) {
            $lr = 102 + $r;
            if ($this->g("B{$lr}") === '') continue;
            $liquidaciones[] = [
                'fecha_pago' => $this->g("B{$lr}"),
                'instrumento_monetario' => $this->clave1($this->g("D{$lr}")),
                'moneda' => $this->clave1($this->g("E{$lr}")),
                'monto_operacion' => $this->g("F{$lr}"),
            ];
        }

        return [
            'tipo_operacion' => $this->clave1($this->g('C27')),
            'sociedades' => $sociedades,
            'liquidaciones' => $liquidaciones,
        ];
    }

    /** Vendedores/compradores for one sociedad, matched by company name. */
    private function parts(string $sociedad, int $fisBase, int $moralBase): array
    {
        $out = [];
        for ($j = 0; $j <= 9; $j++) {
            [$fis, $mor] = [$fisBase + $j, $moralBase + $j];
            if ($this->g("B{$fis}") !== '' && $this->g("J{$fis}") === $sociedad) {
                $out[] = array_merge($this->personaFisica($fis, false), ['numero_acciones' => $this->g("I{$fis}")]);
            } elseif ($this->g("B{$mor}") !== '' && $this->g("G{$mor}") === $sociedad) {
                $out[] = array_merge($this->personaMoral($mor, false), ['numero_acciones' => $this->g("F{$mor}")]);
            } elseif ($this->g("H{$mor}") !== '' && $this->g("L{$mor}") === $sociedad) {
                $out[] = array_merge($this->fideicomisoEsc($mor), ['numero_acciones' => $this->g("K{$mor}")]);
            }
        }
        return $out;
    }
}
