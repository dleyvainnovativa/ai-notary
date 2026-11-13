<?php

namespace Modules\FepAvaluo\V1;

use App\Modules\ModuleInput;

require_once __DIR__ . '/../../fep/v1/Controller.php';

class Controller extends \Modules\Fep\V1\Controller
{
    public function inputs(): array
    {
        return [
            new ModuleInput(
                key: 'escritura',
                label: 'Escritura / avalúo',
                required: true,
                promptPath: 'avaluo_prompt.txt',
                schemaPath: 'avaluo_schema.json',
                outputPath: 'avaluo_output.json',
                description: 'La escritura o documento del avalúo a reportar.',
            ),
        ];
    }

    protected function activityFields(): array
    {
        return [
            'actividad' => [
                'label' => 'Avalúo',
                'type' => 'object',
                'itemSchema' => [
                    'tipo_bien' => ['label' => 'Tipo de bien objeto de avalúo', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_tipo_bien')],
                    'descripcion' => ['label' => 'Descripción del bien', 'type' => 'text', 'col' => 'full'],
                    'valor_avaluo' => ['label' => 'Valor avalúo M.N.', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true, 'money' => true],
                    'propietario' => $this->propietarioObject(),
                ],
            ],
        ];
    }

    protected function activitySubnav(): array
    {
        return [['field' => 'actividad', 'label' => 'Avalúo']];
    }

    /** datos_propietario: one optional person (simple) + the "solicita" flag. */
    private function propietarioObject(): array
    {
        $frag = $this->personaFragment(['variant' => 'simple']);
        return [
            'label' => 'Datos del propietario del bien',
            'type' => 'object',
            'col' => 'full',
            'classifier' => $frag['classifier'],
            'itemSchema' => array_merge([
                'propietario_solicita' => ['label' => '¿El propietario es quien solicita la formalización?', 'type' => 'select', 'col' => 'full', 'options' => [
                    ['label' => 'No', 'value' => 'NO'], ['label' => 'Sí', 'value' => 'SI'],
                ]],
            ], $frag['itemSchema']),
        ];
    }
}
