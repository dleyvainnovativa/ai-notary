<?php

namespace Modules\FepFusion\V1;

use App\Services\Fep\FepXmlWriter;
use App\Services\Fep\FepValueFormatter as F;
use App\Services\Fep\FepPersonas;

require_once __DIR__ . '/../../fep/v1/Exporter.php';

/**
 * FEP — Fusión (<fusion>). XSD fusion_type:
 *   tipo_fusion(1) · datos_fusionadas: datos_fusionada+ (moral block) ·
 *   datos_fusionante: fusionante_determinadas(SI/NO) · fusionante? (moral block +
 *     numero_total_acciones + datos_accionista+ [tipo_persona(simple) + numero_acciones])
 */
class Exporter extends \Modules\Fep\V1\Exporter
{
    protected function buildActivity(FepXmlWriter $w, \DOMElement $ta, array $a): void
    {
        $f = $w->el('fusion');
        $w->req($f, 'tipo_fusion', F::digits($a['tipo_fusion'] ?? '', 1));

        $fds = $w->el('datos_fusionadas');
        foreach ($a['fusionadas'] ?? [] as $m) {
            $fd = $w->el('datos_fusionada');
            self::moralBlock($w, $fd, $m);
            $fds->appendChild($fd);
        }
        $f->appendChild($fds);

        $dft = $w->el('datos_fusionante');
        $w->req($dft, 'fusionante_determinadas', F::siNo($a['fusionante_determinadas'] ?? false));
        $ft = $a['fusionante'] ?? null;
        if (is_array($ft) && ($ft['denominacion_razon'] ?? '') !== '') {
            $ftEl = $w->el('fusionante');
            self::moralBlock($w, $ftEl, $ft);
            $w->req($ftEl, 'numero_total_acciones', F::monto($ft['numero_total_acciones'] ?? 0));
            foreach ($ft['accionistas'] ?? [] as $acc) {
                self::accionista($w, $ftEl, $acc);
            }
            $dft->appendChild($ftEl);
        }
        $f->appendChild($dft);

        $ta->appendChild($f);
    }

    /** denominacion_razon, fecha_constitucion?, rfc?, pais, giro, capital_social_fijo, capital_social_variable?, folio_mercantil? */
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

    /** datos_accionista_1: tipo_persona(simple) + numero_acciones. */
    public static function accionista(FepXmlWriter $w, \DOMElement $parent, array $acc): void
    {
        $da = $w->el('datos_accionista');
        $da->appendChild(FepPersonas::tipoPersona($w, $acc, 'simple'));
        $w->req($da, 'numero_acciones', F::monto($acc['numero_acciones'] ?? 0));
        $parent->appendChild($da);
    }
}
