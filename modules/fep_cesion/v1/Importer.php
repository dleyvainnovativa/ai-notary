<?php

namespace Modules\FepCesion\V1;

use App\Modules\ImportException;

require_once __DIR__ . '/../../fep/v1/Importer.php';

class Importer extends \Modules\Fep\V1\Importer
{
    protected function parseActivity(\DOMElement $ta): array
    {
        $c = $this->first($ta, 'cesion_derechos_fideicomitente_fideicomisario');
        if (!$c) {
            throw new ImportException('El informe no es de tipo Cesión de Derechos.');
        }
        $ced = $this->first($c, 'datos_cedente');
        $ces = $this->first($c, 'datos_cesionario');
        $dc = $this->first($c, 'datos_cesion');
        return [
            'identificador_fideicomiso' => $this->text($c, 'identificador_fideicomiso'),
            'rfc' => $this->text($c, 'rfc'),
            'denominacion_razon' => $this->text($c, 'denominacion_razon'),
            'tipo_cesion' => $this->text($c, 'tipo_cesion'),
            'cedente' => ($tp = $ced ? $this->first($ced, 'tipo_persona') : null) ? $this->persona($tp) : [],
            'cesionario' => ($tp2 = $ces ? $this->first($ces, 'tipo_persona') : null) ? $this->persona($tp2) : [],
            'monto_cesion' => $dc ? $this->text($dc, 'monto_cesion') : '',
        ];
    }
}
