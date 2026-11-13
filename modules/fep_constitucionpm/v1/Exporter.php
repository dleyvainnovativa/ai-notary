<?php

namespace Modules\FepConstitucionpm\V1;

use App\Services\Fep\FepXmlWriter;
use App\Services\Fep\FepValueFormatter as F;
use App\Services\Fep\FepPersonas;

require_once __DIR__ . '/../../fep/v1/Exporter.php';

/**
 * FEP — Constitución de personas morales (<constitucion_personas_morales>). XSD order:
 *   tipo_persona_moral (1-2) · tipo_persona_moral_otra? · denominacion_razon · giro_mercantil (7) ·
 *   folio_mercantil? · numero_total_acciones (monto) · entidad_federativa (1-2) ·
 *   consejo_vigilancia (SI/NO) · motivo_constitucion (1) · instrumento_publico? ·
 *   datos_accionista+ (cargo_accionista(1) · tipo_persona(simple) · numero_acciones(monto)) ·
 *   capital_social (capital_fijo · capital_variable?)
 */
class Exporter extends \Modules\Fep\V1\Exporter
{
    protected function buildActivity(FepXmlWriter $w, \DOMElement $ta, array $a): void
    {
        $c = $w->el('constitucion_personas_morales');
        $w->req($c, 'tipo_persona_moral', F::digits($a['tipo_persona_moral'] ?? '', 2));
        $w->opt($c, 'tipo_persona_moral_otra', F::descripcion($a['tipo_persona_moral_otra'] ?? ''));
        $w->req($c, 'denominacion_razon', F::denominacion($a['denominacion_razon'] ?? ''));
        $w->req($c, 'giro_mercantil', F::digito7($a['giro_mercantil'] ?? ''));
        $w->opt($c, 'folio_mercantil', strtoupper(trim((string) ($a['folio_mercantil'] ?? ''))));
        $w->req($c, 'numero_total_acciones', F::monto($a['numero_total_acciones'] ?? 0));
        $w->req($c, 'entidad_federativa', F::digits($a['entidad_federativa'] ?? '', 2));
        $w->req($c, 'consejo_vigilancia', F::siNo($a['consejo_vigilancia'] ?? false));
        $w->req($c, 'motivo_constitucion', F::digits($a['motivo_constitucion'] ?? '', 1));
        $w->opt($c, 'instrumento_publico', strtoupper(trim((string) ($a['instrumento_publico'] ?? ''))));

        foreach ($a['accionistas'] ?? [] as $acc) {
            $da = $w->el('datos_accionista');
            $w->req($da, 'cargo_accionista', F::digits($acc['cargo_accionista'] ?? '', 1));
            $da->appendChild(FepPersonas::tipoPersona($w, $acc, 'simple'));
            $w->req($da, 'numero_acciones', F::monto($acc['numero_acciones'] ?? 0));
            $c->appendChild($da);
        }

        $cap = $a['capital_social'] ?? [];
        $cs = $w->el('capital_social');
        $w->req($cs, 'capital_fijo', F::monto($cap['capital_fijo'] ?? 0));
        $w->opt($cs, 'capital_variable', isset($cap['capital_variable']) && $cap['capital_variable'] !== '' ? F::monto($cap['capital_variable']) : '');
        $c->appendChild($cs);

        $ta->appendChild($c);
    }
}
