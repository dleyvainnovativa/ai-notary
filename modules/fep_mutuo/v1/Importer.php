<?php

namespace Modules\FepMutuo\V1;

use App\Modules\ImportException;

require_once __DIR__ . '/../../fep/v1/Importer.php';

class Importer extends \Modules\Fep\V1\Importer
{
    protected function parseActivity(\DOMElement $ta): array
    {
        $m = $this->first($ta, 'contrato_mutuo_credito');
        if (!$m) {
            throw new ImportException('El informe no es de tipo Contrato de Mutuo / Crédito.');
        }
        $party = function (string $tag) use ($m) {
            $out = [];
            foreach ($this->all($m, $tag) as $el) {
                $tp = $this->first($el, 'tipo_persona');
                if ($tp) $out[] = $this->persona($tp);
            }
            return $out;
        };
        $garantias = [];
        foreach ($this->all($m, 'datos_garantia') as $el) {
            $g = ['tipo_garantia' => $this->text($el, 'tipo_garantia'), 'clase_bien' => '', 'garante' => []];
            if ($bm = $this->first($el, 'datos_bien_mutuo')) {
                if ($di = $this->first($bm, 'datos_inmueble')) {
                    $g = array_merge($g, ['clase_bien' => 'inmueble', 'tipo_inmueble' => $this->text($di, 'tipo_inmueble'), 'valor_referencia' => $this->text($di, 'valor_referencia'), 'codigo_postal' => $this->text($di, 'codigo_postal'), 'folio_real' => $this->text($di, 'folio_real')]);
                } elseif ($do = $this->first($bm, 'datos_otro')) {
                    $g = array_merge($g, ['clase_bien' => 'otro', 'descripcion_garantia' => $this->text($do, 'descripcion_garantia')]);
                }
            }
            if ($tp = $this->first($el, 'tipo_persona')) {
                $g['garante'] = $this->persona($tp);
            }
            $garantias[] = $g;
        }
        $liquidaciones = [];
        foreach ($this->all($m, 'datos_liquidacion') as $dl) {
            $liquidaciones[] = ['moneda' => $this->text($dl, 'moneda'), 'monto_operacion' => $this->text($dl, 'monto_operacion')];
        }
        return [
            'tipo_otorgamiento' => $this->text($m, 'tipo_otorgamiento'),
            'acreedores' => $party('datos_acreedor'),
            'deudores' => $party('datos_deudor'),
            'garantias' => $garantias,
            'liquidaciones' => $liquidaciones,
        ];
    }
}
