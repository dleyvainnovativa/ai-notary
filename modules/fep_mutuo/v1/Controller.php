<?php

namespace Modules\FepMutuo\V1;

use App\Modules\ModuleInput;

require_once __DIR__ . '/../../fep/v1/Controller.php';

class Controller extends \Modules\Fep\V1\Controller
{
    public function inputs(): array
    {
        return [
            new ModuleInput(
                key: 'escritura',
                label: 'Escritura de mutuo / crédito',
                required: true,
                promptPath: 'mutuo_prompt.txt',
                schemaPath: 'mutuo_schema.json',
                outputPath: 'mutuo_output.json',
                description: 'La escritura de contrato de mutuo, préstamo o crédito.',
            ),
        ];
    }

    protected function activityFields(): array
    {
        $acreedor = array_merge(['label' => 'Acreedores', 'type' => 'array', 'item_label' => 'Acreedor', 'col' => 'full'], $this->personaFragment(['variant' => 'full']));
        $deudor = array_merge(['label' => 'Deudores', 'type' => 'array', 'item_label' => 'Deudor', 'col' => 'full'], $this->personaFragment(['variant' => 'full']));
        $garante = $this->personaFragment(['variant' => 'garante']);

        return [
            'actividad' => [
                'label' => 'Mutuo / Crédito',
                'type' => 'object',
                'itemSchema' => [
                    'tipo_otorgamiento' => ['label' => 'Tipo de Otorgamiento', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_tipo_otorgamiento')],
                    'acreedores' => $acreedor,
                    'deudores' => $deudor,
                    'garantias' => [
                        'label' => 'Garantías', 'type' => 'array', 'item_label' => 'Garantía', 'col' => 'full',
                        'classifier' => ['rule' => 'persona_tipo_select', 'tipo_field' => 'clase_bien'],
                        'itemSchema' => array_merge([
                            'tipo_garantia' => ['label' => 'Tipo de Garantía', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_tipo_garantia')],
                            'clase_bien' => ['label' => 'Bien en garantía', 'type' => 'select', 'options' => [
                                ['label' => 'Sin bien', 'value' => ''], ['label' => 'Inmueble', 'value' => 'inmueble'], ['label' => 'Otro', 'value' => 'otro'],
                            ]],
                            'tipo_inmueble' => ['label' => 'Tipo de Inmueble', 'type' => 'select', 'options' => $this->cat('catalogo_tipo_inmueble'), 'required_in_cases' => ['inmueble']],
                            'valor_referencia' => ['label' => 'Valor de Referencia', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required_in_cases' => ['inmueble'], 'money' => true],
                            'codigo_postal' => ['label' => 'C.P.', 'type' => 'text', 'required_in_cases' => ['inmueble']],
                            'folio_real' => ['label' => 'Folio Real', 'type' => 'text', 'required_in_cases' => ['inmueble']],
                            'descripcion_garantia' => ['label' => 'Descripción de la garantía', 'type' => 'text', 'col' => 'full', 'required_in_cases' => ['otro']],
                            'garante' => array_merge(['label' => 'Garante (opcional)', 'type' => 'object', 'col' => 'full'], $garante),
                        ], []),
                    ],
                    'liquidaciones' => [
                        'label' => 'Liquidaciones', 'type' => 'array', 'item_label' => 'Liquidación', 'col' => 'full',
                        'itemSchema' => [
                            'moneda' => ['label' => 'Moneda', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_moneda')],
                            'monto_operacion' => ['label' => 'Monto', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true, 'money' => true],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function activitySubnav(): array
    {
        return [
            ['field' => 'actividad.acreedores', 'label' => 'Acreedores'],
            ['field' => 'actividad.deudores', 'label' => 'Deudores'],
            ['field' => 'actividad.garantias', 'label' => 'Garantías'],
        ];
    }
}
