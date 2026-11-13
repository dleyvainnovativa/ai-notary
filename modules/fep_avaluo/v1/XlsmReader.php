<?php

namespace Modules\FepAvaluo\V1;

/**
 * Avalúo xlsm → review data. Cells per FedatarioAvaluo_v4_3 Sheet1.cls xmlActividad:
 *   C26 tipo_bien · E26 descripcion · H26 valor_avaluo ·
 *   D29 propietario_solicita · propietario persona: física row 33, moral/fideicomiso row 37.
 */
class XlsmReader extends \App\Services\Fep\FepXlsmReader
{
    public const TEMPLATE = 'FedatarioAvaluo';
    public const VERSION = '4_3';

    protected function readActivity(): array
    {
        $prop = ['propietario_solicita' => $this->g('D29')];
        if ($this->g('D29') !== 'SI') {
            if ($this->g('B33') !== '') {
                $prop = array_merge($prop, $this->personaFisica(33, false));
            } elseif ($this->g('B37') !== '') {
                $prop = array_merge($prop, $this->personaMoral(37, false));
            } elseif ($this->g('G37') !== '') {
                $prop = array_merge($prop, $this->fideicomiso(37));
            }
        }
        return [
            'tipo_bien' => $this->clave1($this->g('C26')),
            'descripcion' => $this->g('E26'),
            'valor_avaluo' => $this->g('H26'),
            'propietario' => $prop,
        ];
    }
}
