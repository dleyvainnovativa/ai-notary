<?php

namespace Modules\FepMutuo\V1;

use App\Services\Fep\FepXmlWriter;
use App\Services\Fep\FepValueFormatter as F;
use App\Services\Fep\FepPersonas;

require_once __DIR__ . '/../../fep/v1/Exporter.php';

/**
 * FEP — Contrato de mutuo / crédito (<contrato_mutuo_credito>). XSD:
 *   tipo_otorgamiento(1) ·
 *   datos_acreedor+ : tipo_persona(full) · datos_deudor+ : tipo_persona(full) ·
 *   datos_garantia* : tipo_garantia(1-2) · datos_bien_mutuo? (datos_inmueble | datos_otro) ·
 *       tipo_persona?(garante) ·
 *   datos_liquidacion+ : moneda(1-3) · monto_operacion(monto)
 */
class Exporter extends \Modules\Fep\V1\Exporter
{
    protected function buildActivity(FepXmlWriter $w, \DOMElement $ta, array $a): void
    {
        $m = $w->el('contrato_mutuo_credito');
        $w->req($m, 'tipo_otorgamiento', F::digits($a['tipo_otorgamiento'] ?? '', 1));

        foreach ($a['acreedores'] ?? [] as $p) {
            $el = $w->el('datos_acreedor');
            $el->appendChild(FepPersonas::tipoPersona($w, $p, 'full'));
            $m->appendChild($el);
        }
        foreach ($a['deudores'] ?? [] as $p) {
            $el = $w->el('datos_deudor');
            $el->appendChild(FepPersonas::tipoPersona($w, $p, 'full'));
            $m->appendChild($el);
        }

        foreach ($a['garantias'] ?? [] as $g) {
            $el = $w->el('datos_garantia');
            $w->req($el, 'tipo_garantia', F::digits($g['tipo_garantia'] ?? '', 2));
            $clase = $g['clase_bien'] ?? '';
            if ($clase === 'inmueble') {
                $bm = $w->el('datos_bien_mutuo');
                $di = $w->el('datos_inmueble');
                $w->req($di, 'tipo_inmueble', F::digits($g['tipo_inmueble'] ?? '', 2));
                $w->req($di, 'valor_referencia', F::monto($g['valor_referencia'] ?? 0));
                $w->req($di, 'codigo_postal', F::digits($g['codigo_postal'] ?? '', 5));
                $w->req($di, 'folio_real', strtoupper(trim((string) ($g['folio_real'] ?? ''))));
                $bm->appendChild($di);
                $el->appendChild($bm);
            } elseif ($clase === 'otro') {
                $bm = $w->el('datos_bien_mutuo');
                $do = $w->el('datos_otro');
                $w->req($do, 'descripcion_garantia', F::descripcion($g['descripcion_garantia'] ?? ''));
                $bm->appendChild($do);
                $el->appendChild($bm);
            }
            if (!empty($g['garante']['tipo']) || !empty($g['garante']['nombre']) || !empty($g['garante']['denominacion_razon'])) {
                $el->appendChild(FepPersonas::tipoPersona($w, $g['garante'], 'garante'));
            }
            $m->appendChild($el);
        }

        foreach ($a['liquidaciones'] ?? [] as $l) {
            $dl = $w->el('datos_liquidacion');
            $w->req($dl, 'moneda', F::digits($l['moneda'] ?? '1', 3) ?: '1');
            $w->req($dl, 'monto_operacion', F::monto($l['monto_operacion'] ?? 0));
            $m->appendChild($dl);
        }

        $ta->appendChild($m);
    }
}
