<?php

namespace Modules\FepCesion\V1;

/**
 * Cesión de derechos xlsm → review data. Cells per FedatarioCesion_v4_3 Sheet1.cls:
 *   B27 identificador_fideicomiso · C27 rfc · D27 denominacion_razon · F27 tipo_cesion ·
 *   cedente: física row 34, moral/fideicomiso row 38 (full personas) ·
 *   cesionario: física row 46, moral/fideicomiso row 50 · C53 monto_cesion.
 */
class XlsmReader extends \App\Services\Fep\FepXlsmReader
{
    public const TEMPLATE = 'FedatarioCesion';
    public const VERSION = '4_3';

    protected function readActivity(): array
    {
        return [
            'identificador_fideicomiso' => $this->g('B27'),
            'rfc' => $this->g('C27'),
            'denominacion_razon' => $this->g('D27'),
            'tipo_cesion' => $this->clave1($this->g('F27')),
            'cedente' => $this->pickPersona(34, 38, 38, true),
            'cesionario' => $this->pickPersona(46, 50, 50, true),
            'monto_cesion' => $this->g('C53'),
        ];
    }
}
