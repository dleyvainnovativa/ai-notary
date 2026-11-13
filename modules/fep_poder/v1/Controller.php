<?php

namespace Modules\FepPoder\V1;

use App\Modules\ModuleInput;

require_once __DIR__ . '/../../fep/v1/Controller.php';

class Controller extends \Modules\Fep\V1\Controller
{
    public function inputs(): array
    {
        return [
            new ModuleInput(
                key: 'escritura',
                label: 'Escritura del poder',
                required: true,
                promptPath: 'poder_prompt.txt',
                schemaPath: 'poder_schema.json',
                outputPath: 'poder_output.json',
                description: 'La escritura de otorgamiento de poder a reportar.',
            ),
        ];
    }

    protected function activityFields(): array
    {
        $poderdante = $this->personaFragment(['variant' => 'full']);
        $apoderado  = $this->personaFragment(['variant' => 'simple']);
        $apoderado['itemSchema'] = array_merge([
            'tipo_poder' => ['label' => 'Tipo de Poder', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_tipo_poder')],
        ], $apoderado['itemSchema']);

        return [
            'actividad' => [
                'label' => 'Otorgamiento de Poder',
                'type' => 'object',
                'itemSchema' => [
                    'poderdantes' => array_merge([
                        'label' => 'Poderdante(s)', 'type' => 'array', 'item_label' => 'Poderdante', 'col' => 'full',
                    ], $poderdante),
                    'apoderados' => array_merge([
                        'label' => 'Apoderado(s)', 'type' => 'array', 'item_label' => 'Apoderado', 'col' => 'full',
                    ], $apoderado),
                ],
            ],
        ];
    }

    protected function activitySubnav(): array
    {
        return [
            ['field' => 'actividad.poderdantes', 'label' => 'Poderdantes'],
            ['field' => 'actividad.apoderados', 'label' => 'Apoderados'],
        ];
    }
}
