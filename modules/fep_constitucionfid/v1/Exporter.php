<?php

namespace Modules\FepConstitucionfid\V1;

use App\Services\Fep\FepXmlWriter;
use App\Services\Fep\FepValueFormatter as F;
use App\Services\Fep\FepPersonas;

require_once __DIR__ . '/../../fep/v1/Exporter.php';

/**
 * FEP — Constitución/Modificación de fideicomiso
 * (<constitucion_modificacion_fideicomiso>). XSD const_mod_fideicomiso_type:
 *   tipo_movimiento(1) · tipo_fideicomiso(1) · descripcion? · rfc? · identificador_fideicomiso? ·
 *   denominacion_razon · monto_patrimonio(monto) ·
 *   datos_fideicomitente+ : tipo_movimiento_fideicomitente(1) · tipo_persona(full) ·
 *       datos_tipo_patrimonio* (choice: patrimonio_monetario | patrimonio_inmueble | patrimonio_otro_bien)
 *   datos_fideicomisarios* : datos_fideicomisarios_determinados(SI/NO) ·
 *       tipo_persona* (tipo_persona_1: tipo_movimiento_fideicomisario(1) + persona(full))
 *   datos_miembro_comite_tecnico? : comite_tecnico(SI/NO) · modificacion_comite_tecnico(SI/NO)
 */
class Exporter extends \Modules\Fep\V1\Exporter
{
    protected function buildActivity(FepXmlWriter $w, \DOMElement $ta, array $a): void
    {
        $c = $w->el('constitucion_modificacion_fideicomiso');
        $w->req($c, 'tipo_movimiento', F::digits($a['tipo_movimiento'] ?? '', 1));
        $w->req($c, 'tipo_fideicomiso', F::digits($a['tipo_fideicomiso'] ?? '', 1));
        $w->opt($c, 'descripcion', F::descripcion($a['descripcion'] ?? ''));
        $w->opt($c, 'rfc', F::rfcMoral($a['rfc'] ?? ''));
        $w->opt($c, 'identificador_fideicomiso', F::denominacion($a['identificador_fideicomiso'] ?? ''));
        $w->req($c, 'denominacion_razon', F::denominacion($a['denominacion_razon'] ?? ''));
        $w->req($c, 'monto_patrimonio', F::monto($a['monto_patrimonio'] ?? 0));

        foreach ($a['fideicomitentes'] ?? [] as $fte) {
            $el = $w->el('datos_fideicomitente');
            $w->req($el, 'tipo_movimiento_fideicomitente', F::digits($fte['tipo_movimiento_fideicomitente'] ?? '', 1));
            $el->appendChild(FepPersonas::tipoPersona($w, $fte, 'full'));
            foreach ($fte['patrimonios'] ?? [] as $pat) {
                $el->appendChild($this->patrimonio($w, $pat));
            }
            $c->appendChild($el);
        }

        foreach ($a['fideicomisarios'] ?? [] as $grp) {
            $el = $w->el('datos_fideicomisarios');
            $w->req($el, 'datos_fideicomisarios_determinados', F::siNo($grp['determinados'] ?? false));
            foreach ($grp['personas'] ?? [] as $p) {
                $el->appendChild(FepPersonas::tipoPersona1($w, $p));
            }
            $c->appendChild($el);
        }

        $ct = $a['comite_tecnico'] ?? null;
        if (is_array($ct) && ($ct['comite_tecnico'] ?? '') !== '') {
            $ctEl = $w->el('datos_miembro_comite_tecnico');
            $w->req($ctEl, 'comite_tecnico', F::siNo($ct['comite_tecnico']));
            $w->req($ctEl, 'modificacion_comite_tecnico', F::siNo($ct['modificacion_comite_tecnico'] ?? false));
            $c->appendChild($ctEl);
        }

        $ta->appendChild($c);
    }

    /** One <datos_tipo_patrimonio> with its single choice child. */
    private function patrimonio(FepXmlWriter $w, array $pat): \DOMElement
    {
        $wrap = $w->el('datos_tipo_patrimonio');
        $clase = $pat['clase'] ?? 'monetario';
        if ($clase === 'inmueble') {
            $n = $w->el('patrimonio_inmueble');
            $w->req($n, 'tipo_inmueble', F::digits($pat['tipo_inmueble'] ?? '', 2));
            $w->req($n, 'codigo_postal', F::digits($pat['codigo_postal'] ?? '', 5));
            $w->req($n, 'folio_real', strtoupper(trim((string) ($pat['folio_real'] ?? ''))));
            $w->req($n, 'importe_garantia', F::monto($pat['importe_garantia'] ?? 0));
        } elseif ($clase === 'otro') {
            $n = $w->el('patrimonio_otro_bien');
            $w->req($n, 'descripcion', F::descripcion($pat['descripcion'] ?? ''));
            $w->req($n, 'valor_bien', F::monto($pat['valor_bien'] ?? 0));
        } else {
            $n = $w->el('patrimonio_monetario');
            $w->req($n, 'moneda', F::digits($pat['moneda'] ?? '1', 3) ?: '1');
            $w->req($n, 'monto_operacion', F::monto($pat['monto_operacion'] ?? 0));
        }
        $wrap->appendChild($n);
        return $wrap;
    }
}
