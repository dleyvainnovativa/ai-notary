<?php

namespace Modules\FepCesion\V1;

use App\Modules\ModuleInput;

require_once __DIR__ . '/../../fep/v1/Controller.php';

class Controller extends \Modules\Fep\V1\Controller
{
    public function inputs(): array
    {
        return [
            new ModuleInput(
                key: 'escritura',
                label: 'Escritura de cesión',
                required: true,
                promptPath: 'cesion_prompt.txt',
                schemaPath: 'cesion_schema.json',
                outputPath: 'cesion_output.json',
                description: 'La escritura de cesión de derechos del fideicomiso a reportar.',
            ),
        ];
    }

    protected function activityFields(): array
    {
        $cedente    = $this->personaFragment(['variant' => 'full']);
        $cesionario = $this->personaFragment(['variant' => 'full']);

        return [
            'actividad' => [
                'label' => 'Cesión de Derechos',
                'type' => 'object',
                'itemSchema' => [
                    'identificador_fideicomiso' => ['label' => 'Identificador del Fideicomiso', 'type' => 'text'],
                    'rfc' => ['label' => 'RFC del Fideicomiso', 'type' => 'text', 'validation' => ['format' => 'rfc']],
                    'denominacion_razon' => ['label' => 'Denominación / Razón Social del Fideicomiso', 'type' => 'text', 'col' => 'full', 'required' => true],
                    'tipo_cesion' => ['label' => 'Tipo de Cesión', 'type' => 'select', 'col' => 'full', 'required' => true, 'options' => $this->cat('catalogo_tipo_cesion')],
                    'cedente' => array_merge(['label' => 'Cedente', 'type' => 'object', 'col' => 'full'], $cedente),
                    'cesionario' => array_merge(['label' => 'Cesionario', 'type' => 'object', 'col' => 'full'], $cesionario),
                    'monto_cesion' => ['label' => 'Monto de la Cesión M.N.', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true, 'money' => true],
                ],
            ],
        ];
    }

    protected function activitySubnav(): array
    {
        return [['field' => 'actividad', 'label' => 'Cesión']];
    }
}
