<?php

namespace Modules\FepPoder\V1;

use App\Modules\ImportException;

require_once __DIR__ . '/../../fep/v1/Importer.php';

class Importer extends \Modules\Fep\V1\Importer
{
    protected function parseActivity(\DOMElement $ta): array
    {
        $op = $this->first($ta, 'otorgamiento_poder');
        if (!$op) {
            throw new ImportException('El informe no es de tipo Otorgamiento de Poder.');
        }
        $poderdantes = [];
        foreach ($this->all($op, 'datos_poderdante') as $dp) {
            $tp = $this->first($dp, 'tipo_persona');
            if ($tp) $poderdantes[] = $this->persona($tp);
        }
        $apoderados = [];
        foreach ($this->all($op, 'datos_apoderado') as $da) {
            $tp = $this->first($da, 'tipo_persona');
            $apoderados[] = array_merge(
                ['tipo_poder' => $this->text($da, 'tipo_poder')],
                $tp ? $this->persona($tp) : []
            );
        }
        return ['poderdantes' => $poderdantes, 'apoderados' => $apoderados];
    }
}
