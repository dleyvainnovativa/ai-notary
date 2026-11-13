<?php

namespace Modules\FepCesion\V1;

use App\Services\Fep\FepXmlWriter;
use App\Services\Fep\FepValueFormatter as F;
use App\Services\Fep\FepPersonas;

require_once __DIR__ . '/../../fep/v1/Exporter.php';

/**
 * FEP — Cesión de derechos del fideicomitente/fideicomisario
 * (<cesion_derechos_fideicomitente_fideicomisario>). XSD cesion_derechos_type:
 *   identificador_fideicomiso? · rfc? (moral) · denominacion_razon · tipo_cesion (1 díg) ·
 *   datos_cedente: tipo_persona (full) · datos_cesionario: tipo_persona (full) ·
 *   datos_cesion: monto_cesion
 */
class Exporter extends \Modules\Fep\V1\Exporter
{
    protected function buildActivity(FepXmlWriter $w, \DOMElement $ta, array $a): void
    {
        $c = $w->el('cesion_derechos_fideicomitente_fideicomisario');
        $w->opt($c, 'identificador_fideicomiso', F::denominacion($a['identificador_fideicomiso'] ?? ''));
        $w->opt($c, 'rfc', F::rfcMoral($a['rfc'] ?? ''));
        $w->req($c, 'denominacion_razon', F::denominacion($a['denominacion_razon'] ?? ''));
        $w->req($c, 'tipo_cesion', F::digits($a['tipo_cesion'] ?? '', 1));

        $ced = $w->el('datos_cedente');
        $ced->appendChild(FepPersonas::tipoPersona($w, $a['cedente'] ?? [], 'full'));
        $c->appendChild($ced);

        $ces = $w->el('datos_cesionario');
        $ces->appendChild(FepPersonas::tipoPersona($w, $a['cesionario'] ?? [], 'full'));
        $c->appendChild($ces);

        $dc = $w->el('datos_cesion');
        $w->req($dc, 'monto_cesion', F::monto($a['monto_cesion'] ?? 0));
        $c->appendChild($dc);

        $ta->appendChild($c);
    }
}
