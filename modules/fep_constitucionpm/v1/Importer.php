<?php

namespace Modules\FepConstitucionpm\V1;

use App\Modules\ImportException;

require_once __DIR__ . '/../../fep/v1/Importer.php';

class Importer extends \Modules\Fep\V1\Importer
{
    protected function parseActivity(\DOMElement $ta): array
    {
        $c = $this->first($ta, 'constitucion_personas_morales');
        if (!$c) {
            throw new ImportException('El informe no es de tipo Constitución de Personas Morales.');
        }
        $accionistas = [];
        foreach ($this->all($c, 'datos_accionista') as $da) {
            $tp = $this->first($da, 'tipo_persona');
            $accionistas[] = array_merge(
                ['cargo_accionista' => $this->text($da, 'cargo_accionista')],
                $tp ? $this->persona($tp) : [],
                ['numero_acciones' => $this->text($da, 'numero_acciones')]
            );
        }
        $cap = $this->first($c, 'capital_social');
        return [
            'tipo_persona_moral' => $this->text($c, 'tipo_persona_moral'),
            'tipo_persona_moral_otra' => $this->text($c, 'tipo_persona_moral_otra'),
            'denominacion_razon' => $this->text($c, 'denominacion_razon'),
            'giro_mercantil' => $this->text($c, 'giro_mercantil'),
            'folio_mercantil' => $this->text($c, 'folio_mercantil'),
            'numero_total_acciones' => $this->text($c, 'numero_total_acciones'),
            'entidad_federativa' => $this->text($c, 'entidad_federativa'),
            'consejo_vigilancia' => $this->text($c, 'consejo_vigilancia'),
            'motivo_constitucion' => $this->text($c, 'motivo_constitucion'),
            'instrumento_publico' => $this->text($c, 'instrumento_publico'),
            'accionistas' => $accionistas,
            'capital_social' => [
                'capital_fijo' => $cap ? $this->text($cap, 'capital_fijo') : '',
                'capital_variable' => $cap ? $this->text($cap, 'capital_variable') : '',
            ],
        ];
    }
}
