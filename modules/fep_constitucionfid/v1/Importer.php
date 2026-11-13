<?php

namespace Modules\FepConstitucionfid\V1;

use App\Modules\ImportException;

require_once __DIR__ . '/../../fep/v1/Importer.php';

class Importer extends \Modules\Fep\V1\Importer
{
    protected function parseActivity(\DOMElement $ta): array
    {
        $c = $this->first($ta, 'constitucion_modificacion_fideicomiso');
        if (!$c) {
            throw new ImportException('El informe no es de tipo Constitución/Modificación de Fideicomiso.');
        }

        $fideicomitentes = [];
        foreach ($this->all($c, 'datos_fideicomitente') as $el) {
            $tp = $this->first($el, 'tipo_persona');
            $patrimonios = [];
            foreach ($this->all($el, 'datos_tipo_patrimonio') as $wrap) {
                $patrimonios[] = $this->patrimonio($wrap);
            }
            $fideicomitentes[] = array_merge(
                ['tipo_movimiento_fideicomitente' => $this->text($el, 'tipo_movimiento_fideicomitente')],
                $tp ? $this->persona($tp) : [],
                ['patrimonios' => $patrimonios]
            );
        }

        $fideicomisarios = [];
        foreach ($this->all($c, 'datos_fideicomisarios') as $grp) {
            $personas = [];
            foreach ($this->all($grp, 'tipo_persona') as $tp) {
                $personas[] = array_merge(
                    ['tipo_movimiento_fideicomisario' => $this->text($tp, 'tipo_movimiento_fideicomisario')],
                    $this->persona($tp)
                );
            }
            $fideicomisarios[] = [
                'determinados' => $this->text($grp, 'datos_fideicomisarios_determinados'),
                'personas' => $personas,
            ];
        }

        $ct = $this->first($c, 'datos_miembro_comite_tecnico');

        return [
            'tipo_movimiento' => $this->text($c, 'tipo_movimiento'),
            'tipo_fideicomiso' => $this->text($c, 'tipo_fideicomiso'),
            'descripcion' => $this->text($c, 'descripcion'),
            'rfc' => $this->text($c, 'rfc'),
            'identificador_fideicomiso' => $this->text($c, 'identificador_fideicomiso'),
            'denominacion_razon' => $this->text($c, 'denominacion_razon'),
            'monto_patrimonio' => $this->text($c, 'monto_patrimonio'),
            'fideicomitentes' => $fideicomitentes,
            'fideicomisarios' => $fideicomisarios,
            'comite_tecnico' => $ct ? [
                'comite_tecnico' => $this->text($ct, 'comite_tecnico'),
                'modificacion_comite_tecnico' => $this->text($ct, 'modificacion_comite_tecnico'),
            ] : null,
        ];
    }

    private function patrimonio(\DOMElement $wrap): array
    {
        if ($n = $this->first($wrap, 'patrimonio_inmueble')) {
            return ['clase' => 'inmueble', 'tipo_inmueble' => $this->text($n, 'tipo_inmueble'), 'codigo_postal' => $this->text($n, 'codigo_postal'), 'folio_real' => $this->text($n, 'folio_real'), 'importe_garantia' => $this->text($n, 'importe_garantia')];
        }
        if ($n = $this->first($wrap, 'patrimonio_otro_bien')) {
            return ['clase' => 'otro', 'descripcion' => $this->text($n, 'descripcion'), 'valor_bien' => $this->text($n, 'valor_bien')];
        }
        $n = $this->first($wrap, 'patrimonio_monetario');
        return ['clase' => 'monetario', 'moneda' => $n ? $this->text($n, 'moneda') : '', 'monto_operacion' => $n ? $this->text($n, 'monto_operacion') : ''];
    }
}
