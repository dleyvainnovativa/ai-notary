<?php

namespace Modules\FepAvaluo\V1;

use App\Services\Fep\FepXmlWriter;
use App\Services\Fep\FepValueFormatter as F;
use App\Services\Fep\FepPersonas;

require_once __DIR__ . '/../../fep/v1/Exporter.php';

/**
 * FEP — Avalúo (<avaluo>). XSD avaluo_type:
 *   tipo_bien (1-2 díg) · descripcion? · valor_avaluo (monto) ·
 *   datos_propietario: propietario_solicita (SI/NO) + tipo_persona_simple?
 */
class Exporter extends \Modules\Fep\V1\Exporter
{
    protected function buildActivity(FepXmlWriter $w, \DOMElement $ta, array $a): void
    {
        $av = $w->el('avaluo');
        $w->req($av, 'tipo_bien', F::digits($a['tipo_bien'] ?? '', 2));
        $w->opt($av, 'descripcion', F::descripcion($a['descripcion'] ?? ''));
        $w->req($av, 'valor_avaluo', F::monto($a['valor_avaluo'] ?? 0));

        $prop = $a['propietario'] ?? [];
        $dp = $w->el('datos_propietario');
        $w->req($dp, 'propietario_solicita', F::siNo($prop['propietario_solicita'] ?? ($a['propietario_solicita'] ?? false)));
        // tipo_persona is optional; include it only when a person was captured.
        if (!empty($prop['tipo']) || !empty($prop['nombre']) || !empty($prop['denominacion_razon'])) {
            $dp->appendChild(FepPersonas::tipoPersona($w, $prop, 'simple'));
        }
        $av->appendChild($dp);
        $ta->appendChild($av);
    }
}
