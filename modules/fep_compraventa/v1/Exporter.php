<?php

namespace Modules\FepCompraventa\V1;

use App\Services\Fep\FepXmlWriter;
use App\Services\Fep\FepValueFormatter as F;
use App\Services\Fep\FepPersonas;

require_once __DIR__ . '/../../fep/v1/Exporter.php';

/**
 * FEP — Compraventa de acciones (<compra_venta_acciones>). XSD:
 *   tipo_operacion(1) ·
 *   persona_moral_acciones+ : denominacion_razon · fecha_constitucion? · rfc? · pais_nacionalidad ·
 *       valor_nominal(monto) · numero_acciones(monto) ·
 *       datos_vendedor+ (numero_acciones_vendidas(monto) · tipo_persona(simple)) ·
 *       datos_comprador+ (numero_acciones_compradas(monto) · tipo_persona(simple)) ·
 *   datos_liquidacion+ : fecha_pago · instrumento_monetario?(1-2) · moneda(1-3) · monto_operacion(monto)
 */
class Exporter extends \Modules\Fep\V1\Exporter
{
    protected function buildActivity(FepXmlWriter $w, \DOMElement $ta, array $a): void
    {
        $c = $w->el('compra_venta_acciones');
        $w->req($c, 'tipo_operacion', F::digits($a['tipo_operacion'] ?? '', 1));

        foreach ($a['sociedades'] ?? [] as $s) {
            $pm = $w->el('persona_moral_acciones');
            $w->req($pm, 'denominacion_razon', F::denominacion($s['denominacion_razon'] ?? ''));
            $w->opt($pm, 'fecha_constitucion', F::fecha($s['fecha_constitucion'] ?? ''));
            $w->opt($pm, 'rfc', F::rfcMoral($s['rfc'] ?? ''));
            $w->req($pm, 'pais_nacionalidad', F::pais($s['pais_nacionalidad'] ?? ''));
            $w->req($pm, 'valor_nominal', F::monto($s['valor_nominal'] ?? 0));
            $w->req($pm, 'numero_acciones', F::monto($s['numero_acciones'] ?? 0));
            foreach ($s['vendedores'] ?? [] as $v) {
                $dv = $w->el('datos_vendedor');
                $w->req($dv, 'numero_acciones_vendidas', F::monto($v['numero_acciones'] ?? 0));
                $dv->appendChild(FepPersonas::tipoPersona($w, $v, 'simple'));
                $pm->appendChild($dv);
            }
            foreach ($s['compradores'] ?? [] as $cm) {
                $dc = $w->el('datos_comprador');
                $w->req($dc, 'numero_acciones_compradas', F::monto($cm['numero_acciones'] ?? 0));
                $dc->appendChild(FepPersonas::tipoPersona($w, $cm, 'simple'));
                $pm->appendChild($dc);
            }
            $c->appendChild($pm);
        }

        foreach ($a['liquidaciones'] ?? [] as $l) {
            $dl = $w->el('datos_liquidacion');
            $w->req($dl, 'fecha_pago', F::fecha($l['fecha_pago'] ?? ''));
            $w->opt($dl, 'instrumento_monetario', F::digits($l['instrumento_monetario'] ?? '', 2));
            $w->req($dl, 'moneda', F::digits($l['moneda'] ?? '1', 3) ?: '1');
            $w->req($dl, 'monto_operacion', F::monto($l['monto_operacion'] ?? 0));
            $c->appendChild($dl);
        }

        $ta->appendChild($c);
    }
}
