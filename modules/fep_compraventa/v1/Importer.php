<?php

namespace Modules\FepCompraventa\V1;

use App\Modules\ImportException;

require_once __DIR__ . '/../../fep/v1/Importer.php';

class Importer extends \Modules\Fep\V1\Importer
{
    protected function parseActivity(\DOMElement $ta): array
    {
        $c = $this->first($ta, 'compra_venta_acciones');
        if (!$c) {
            throw new ImportException('El informe no es de tipo Compraventa de Acciones.');
        }
        $sociedades = [];
        foreach ($this->all($c, 'persona_moral_acciones') as $pm) {
            $vendedores = [];
            foreach ($this->all($pm, 'datos_vendedor') as $dv) {
                $tp = $this->first($dv, 'tipo_persona');
                $vendedores[] = array_merge($tp ? $this->persona($tp) : [], ['numero_acciones' => $this->text($dv, 'numero_acciones_vendidas')]);
            }
            $compradores = [];
            foreach ($this->all($pm, 'datos_comprador') as $dc) {
                $tp = $this->first($dc, 'tipo_persona');
                $compradores[] = array_merge($tp ? $this->persona($tp) : [], ['numero_acciones' => $this->text($dc, 'numero_acciones_compradas')]);
            }
            $sociedades[] = [
                'denominacion_razon' => $this->text($pm, 'denominacion_razon'),
                'fecha_constitucion' => $this->text($pm, 'fecha_constitucion'),
                'rfc' => $this->text($pm, 'rfc'),
                'pais_nacionalidad' => $this->text($pm, 'pais_nacionalidad'),
                'valor_nominal' => $this->text($pm, 'valor_nominal'),
                'numero_acciones' => $this->text($pm, 'numero_acciones'),
                'vendedores' => $vendedores,
                'compradores' => $compradores,
            ];
        }
        $liquidaciones = [];
        foreach ($this->all($c, 'datos_liquidacion') as $dl) {
            $liquidaciones[] = [
                'fecha_pago' => $this->text($dl, 'fecha_pago'),
                'instrumento_monetario' => $this->text($dl, 'instrumento_monetario'),
                'moneda' => $this->text($dl, 'moneda'),
                'monto_operacion' => $this->text($dl, 'monto_operacion'),
            ];
        }
        return [
            'tipo_operacion' => $this->text($c, 'tipo_operacion'),
            'sociedades' => $sociedades,
            'liquidaciones' => $liquidaciones,
        ];
    }
}
