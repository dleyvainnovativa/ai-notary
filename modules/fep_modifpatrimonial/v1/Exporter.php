<?php

namespace Modules\FepModifpatrimonial\V1;

use App\Services\Fep\FepXmlWriter;
use App\Services\Fep\FepValueFormatter as F;
use App\Services\Fep\FepPersonas;

require_once __DIR__ . '/../../fep/v1/Exporter.php';

/**
 * FEP — Modificación patrimonial (<modificacion_patrimonial>). XSD:
 *   persona_moral_modifica: denominacion_razon · fecha_constitucion? · rfc? · pais_nacionalidad ·
 *     giro_mercantil · numero_total_acciones · motivo_modificacion (1) · instrumento_publico?
 *   datos_modificacion: tipo_modificacion_capital_fijo (1) · inicial/final_capital_fijo (monto) ·
 *     tipo_modificacion_capital_variable (1) · inicial/final_capital_variable (monto) ·
 *     datos_accionista+ (tipo_persona(simple) · numero_acciones)
 */
class Exporter extends \Modules\Fep\V1\Exporter
{
    protected function buildActivity(FepXmlWriter $w, \DOMElement $ta, array $a): void
    {
        $m = $w->el('modificacion_patrimonial');

        $pm = $a['persona_moral_modifica'] ?? [];
        $pmEl = $w->el('persona_moral_modifica');
        $w->req($pmEl, 'denominacion_razon', F::denominacion($pm['denominacion_razon'] ?? ''));
        $w->opt($pmEl, 'fecha_constitucion', F::fecha($pm['fecha_constitucion'] ?? ''));
        $w->opt($pmEl, 'rfc', F::rfcMoral($pm['rfc'] ?? ''));
        $w->req($pmEl, 'pais_nacionalidad', F::pais($pm['pais_nacionalidad'] ?? ''));
        $w->req($pmEl, 'giro_mercantil', F::digito7($pm['giro_mercantil'] ?? ''));
        $w->req($pmEl, 'numero_total_acciones', F::monto($pm['numero_total_acciones'] ?? 0));
        $w->req($pmEl, 'motivo_modificacion', F::digits($pm['motivo_modificacion'] ?? '', 1));
        $w->opt($pmEl, 'instrumento_publico', strtoupper(trim((string) ($pm['instrumento_publico'] ?? ''))));
        $m->appendChild($pmEl);

        $dm = $a['datos_modificacion'] ?? [];
        $dmEl = $w->el('datos_modificacion');
        $w->req($dmEl, 'tipo_modificacion_capital_fijo', F::digits($dm['tipo_modificacion_capital_fijo'] ?? '', 1));
        $w->req($dmEl, 'inicial_capital_fijo', F::monto($dm['inicial_capital_fijo'] ?? 0));
        $w->req($dmEl, 'final_capital_fijo', F::monto($dm['final_capital_fijo'] ?? 0));
        $w->req($dmEl, 'tipo_modificacion_capital_variable', F::digits($dm['tipo_modificacion_capital_variable'] ?? '', 1));
        $w->req($dmEl, 'inicial_capital_variable', F::monto($dm['inicial_capital_variable'] ?? 0));
        $w->req($dmEl, 'final_capital_variable', F::monto($dm['final_capital_variable'] ?? 0));
        foreach ($dm['accionistas'] ?? [] as $acc) {
            $da = $w->el('datos_accionista');
            $da->appendChild(FepPersonas::tipoPersona($w, $acc, 'simple'));
            $w->req($da, 'numero_acciones', F::monto($acc['numero_acciones'] ?? 0));
            $dmEl->appendChild($da);
        }
        $m->appendChild($dmEl);

        $ta->appendChild($m);
    }
}
