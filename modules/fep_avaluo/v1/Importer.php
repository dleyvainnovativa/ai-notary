<?php

namespace Modules\FepAvaluo\V1;

use App\Modules\ImportException;

require_once __DIR__ . '/../../fep/v1/Importer.php';

class Importer extends \Modules\Fep\V1\Importer
{
    protected function parseActivity(\DOMElement $ta): array
    {
        $av = $this->first($ta, 'avaluo');
        if (!$av) {
            throw new ImportException('El informe no es de tipo Avalúo.');
        }
        $dp = $this->first($av, 'datos_propietario');
        $prop = [];
        if ($dp) {
            $prop['propietario_solicita'] = $this->text($dp, 'propietario_solicita');
            $tp = $this->first($dp, 'tipo_persona');
            if ($tp) $prop = array_merge($prop, $this->persona($tp));
        }
        return [
            'tipo_bien' => $this->text($av, 'tipo_bien'),
            'descripcion' => $this->text($av, 'descripcion'),
            'valor_avaluo' => $this->text($av, 'valor_avaluo'),
            'propietario' => $prop,
        ];
    }
}
