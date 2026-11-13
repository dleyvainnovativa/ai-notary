<?php

namespace Modules\FepEscision\V1;

use App\Services\Fep\FepXmlWriter;
use App\Services\Fep\FepValueFormatter as F;
use App\Services\Fep\FepPersonas;

require_once __DIR__ . '/../../fep/v1/Exporter.php';

/**
 * FEP — Escisión (<escision>). XSD escision_type:
 *   datos_escindente: moral block · escindente_subsiste(SI/NO) · datos_accionista_escindente*
 *   datos_escindidas: escindidas_determinadas(SI/NO) · dato_escindida* (moral block +
 *     numero_total_acciones + datos_accionista+)
 *   accionistas use datos_accionista_1 (tipo_persona(simple) + numero_acciones).
 */
class Exporter extends \Modules\Fep\V1\Exporter
{
    protected function buildActivity(FepXmlWriter $w, \DOMElement $ta, array $a): void
    {
        $e = $w->el('escision');

        $esc = $a['escindente'] ?? [];
        $escEl = $w->el('datos_escindente');
        self::moralBlock($w, $escEl, $esc);
        $w->req($escEl, 'escindente_subsiste', F::siNo($esc['escindente_subsiste'] ?? false));
        foreach ($esc['accionistas'] ?? [] as $acc) {
            self::accionista($w, $escEl, $acc, 'datos_accionista_escindente');
        }
        $e->appendChild($escEl);

        $dsds = $w->el('datos_escindidas');
        $w->req($dsds, 'escindidas_determinadas', F::siNo($a['escindidas_determinadas'] ?? false));
        foreach ($a['escindidas'] ?? [] as $es) {
            $deEl = $w->el('dato_escindida');
            self::moralBlock($w, $deEl, $es);
            $w->req($deEl, 'numero_total_acciones', F::monto($es['numero_total_acciones'] ?? 0));
            foreach ($es['accionistas'] ?? [] as $acc) {
                self::accionista($w, $deEl, $acc, 'datos_accionista');
            }
            $dsds->appendChild($deEl);
        }
        $e->appendChild($dsds);

        $ta->appendChild($e);
    }

    public static function moralBlock(FepXmlWriter $w, \DOMElement $n, array $m): void
    {
        $w->req($n, 'denominacion_razon', F::denominacion($m['denominacion_razon'] ?? ''));
        $w->opt($n, 'fecha_constitucion', F::fecha($m['fecha_constitucion'] ?? ''));
        $w->opt($n, 'rfc', F::rfcMoral($m['rfc'] ?? ''));
        $w->req($n, 'pais_nacionalidad', F::pais($m['pais_nacionalidad'] ?? ''));
        $w->req($n, 'giro_mercantil', F::digito7($m['giro_mercantil'] ?? ''));
        $w->req($n, 'capital_social_fijo', F::monto($m['capital_social_fijo'] ?? 0));
        $w->opt($n, 'capital_social_variable', isset($m['capital_social_variable']) && $m['capital_social_variable'] !== '' ? F::monto($m['capital_social_variable']) : '');
        $w->opt($n, 'folio_mercantil', strtoupper(trim((string) ($m['folio_mercantil'] ?? ''))));
    }

    public static function accionista(FepXmlWriter $w, \DOMElement $parent, array $acc, string $tag): void
    {
        $da = $w->el($tag);
        $da->appendChild(FepPersonas::tipoPersona($w, $acc, 'simple'));
        $w->req($da, 'numero_acciones', F::monto($acc['numero_acciones'] ?? 0));
        $parent->appendChild($da);
    }
}
