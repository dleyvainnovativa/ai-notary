<?php

namespace Modules\FepPoder\V1;

use App\Services\Fep\FepXmlWriter;
use App\Services\Fep\FepValueFormatter as F;
use App\Services\Fep\FepPersonas;

require_once __DIR__ . '/../../fep/v1/Exporter.php';

/**
 * FEP — Otorgamiento de poder (<otorgamiento_poder>). XSD:
 *   datos_poderdante+ : tipo_persona (full)
 *   datos_apoderado+  : tipo_poder (1 díg) + tipo_persona (simple)
 */
class Exporter extends \Modules\Fep\V1\Exporter
{
    protected function buildActivity(FepXmlWriter $w, \DOMElement $ta, array $a): void
    {
        $op = $w->el('otorgamiento_poder');

        foreach ($a['poderdantes'] ?? [] as $p) {
            $dp = $w->el('datos_poderdante');
            $dp->appendChild(FepPersonas::tipoPersona($w, $p, 'full'));
            $op->appendChild($dp);
        }
        foreach ($a['apoderados'] ?? [] as $p) {
            $da = $w->el('datos_apoderado');
            $w->req($da, 'tipo_poder', F::digits($p['tipo_poder'] ?? '', 1));
            $da->appendChild(FepPersonas::tipoPersona($w, $p, 'simple'));
            $op->appendChild($da);
        }
        $ta->appendChild($op);
    }
}
