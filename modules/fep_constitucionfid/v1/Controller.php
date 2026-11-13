<?php

namespace Modules\FepConstitucionfid\V1;

use App\Modules\ModuleInput;

require_once __DIR__ . '/../../fep/v1/Controller.php';

class Controller extends \Modules\Fep\V1\Controller
{
    public function inputs(): array
    {
        return [
            new ModuleInput(
                key: 'escritura',
                label: 'Escritura del fideicomiso',
                required: true,
                promptPath: 'constitucionfid_prompt.txt',
                schemaPath: 'constitucionfid_schema.json',
                outputPath: 'constitucionfid_output.json',
                description: 'La escritura de constitución o modificación del fideicomiso.',
            ),
        ];
    }

    protected function activityFields(): array
    {
        $catMov = $this->cat('catalogo_tipo_movimiento_persona');

        // Fideicomitente: movimiento + full persona + patrimonios[]
        $fideicomitente = $this->personaFragment(['variant' => 'full']);
        $fideicomitente['itemSchema'] = array_merge(
            ['tipo_movimiento_fideicomitente' => ['label' => 'Tipo de Movimiento', 'type' => 'select', 'required' => true, 'options' => $catMov]],
            $fideicomitente['itemSchema'],
            ['patrimonios' => $this->patrimoniosArray()]
        );

        // Fideicomisario persona: movimiento + full persona
        $fideicomisarioPersona = $this->personaFragment(['variant' => 'full']);
        $fideicomisarioPersona['itemSchema'] = array_merge(
            ['tipo_movimiento_fideicomisario' => ['label' => 'Tipo de Movimiento', 'type' => 'select', 'required' => true, 'options' => $catMov]],
            $fideicomisarioPersona['itemSchema']
        );

        return [
            'actividad' => [
                'label' => 'Fideicomiso',
                'type' => 'object',
                'itemSchema' => [
                    'tipo_movimiento' => ['label' => 'Tipo de Movimiento', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_tipo_movimiento')],
                    'tipo_fideicomiso' => ['label' => 'Tipo de Fideicomiso', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_tipo_fideicomiso')],
                    'descripcion' => ['label' => 'Descripción', 'type' => 'text', 'col' => 'full'],
                    'rfc' => ['label' => 'RFC del Fideicomiso', 'type' => 'text', 'validation' => ['format' => 'rfc']],
                    'identificador_fideicomiso' => ['label' => 'Identificador del Fideicomiso', 'type' => 'text'],
                    'denominacion_razon' => ['label' => 'Denominación / Fiduciario', 'type' => 'text', 'col' => 'full', 'required' => true],
                    'monto_patrimonio' => ['label' => 'Monto del Patrimonio M.N.', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true, 'money' => true],
                    'fideicomitentes' => array_merge(['label' => 'Fideicomitentes', 'type' => 'array', 'item_label' => 'Fideicomitente', 'col' => 'full'], $fideicomitente),
                    'fideicomisarios' => [
                        'label' => 'Fideicomisarios', 'type' => 'array', 'item_label' => 'Grupo de fideicomisarios', 'col' => 'full',
                        'itemSchema' => [
                            'determinados' => ['label' => '¿Fideicomisarios determinados?', 'type' => 'select', 'col' => 'full', 'required' => true, 'options' => [['label' => 'No', 'value' => 'NO'], ['label' => 'Sí', 'value' => 'SI']]],
                            'personas' => array_merge(['label' => 'Personas', 'type' => 'array', 'item_label' => 'Fideicomisario', 'col' => 'full'], $fideicomisarioPersona),
                        ],
                    ],
                    'comite_tecnico' => [
                        'label' => 'Comité Técnico', 'type' => 'object', 'col' => 'full',
                        'itemSchema' => [
                            'comite_tecnico' => ['label' => '¿Existe comité técnico?', 'type' => 'select', 'options' => [['label' => 'No', 'value' => 'NO'], ['label' => 'Sí', 'value' => 'SI']]],
                            'modificacion_comite_tecnico' => ['label' => '¿Modificación al comité técnico?', 'type' => 'select', 'options' => [['label' => 'No', 'value' => 'NO'], ['label' => 'Sí', 'value' => 'SI']]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** patrimonios[] with a 'clase' classifier → monetario / inmueble / otro field sets. */
    private function patrimoniosArray(): array
    {
        return [
            'label' => 'Patrimonio aportado', 'type' => 'array', 'item_label' => 'Bien', 'col' => 'full',
            'classifier' => ['rule' => 'persona_tipo_select', 'tipo_field' => 'clase'],
            'itemSchema' => [
                'clase' => ['label' => 'Tipo de bien', 'type' => 'select', 'required' => true, 'options' => [
                    ['label' => 'Monetario', 'value' => 'monetario'],
                    ['label' => 'Inmueble', 'value' => 'inmueble'],
                    ['label' => 'Otro bien', 'value' => 'otro'],
                ]],
                // monetario
                'moneda' => ['label' => 'Moneda', 'type' => 'select', 'options' => $this->cat('catalogo_moneda'), 'required_in_cases' => ['monetario']],
                'monto_operacion' => ['label' => 'Monto', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required_in_cases' => ['monetario'], 'money' => true],
                // inmueble
                'tipo_inmueble' => ['label' => 'Tipo de Inmueble', 'type' => 'select', 'options' => $this->cat('catalogo_tipo_inmueble'), 'required_in_cases' => ['inmueble']],
                'codigo_postal' => ['label' => 'C.P.', 'type' => 'text', 'required_in_cases' => ['inmueble']],
                'folio_real' => ['label' => 'Folio Real', 'type' => 'text', 'required_in_cases' => ['inmueble']],
                'importe_garantia' => ['label' => 'Importe / Valor', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required_in_cases' => ['inmueble'], 'money' => true],
                // otro
                'descripcion' => ['label' => 'Descripción del bien', 'type' => 'text', 'col' => 'full', 'required_in_cases' => ['otro']],
                'valor_bien' => ['label' => 'Valor del bien', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required_in_cases' => ['otro'], 'money' => true],
            ],
        ];
    }

    protected function activitySubnav(): array
    {
        return [
            ['field' => 'actividad', 'label' => 'Fideicomiso'],
            ['field' => 'actividad.fideicomitentes', 'label' => 'Fideicomitentes'],
            ['field' => 'actividad.fideicomisarios', 'label' => 'Fideicomisarios'],
        ];
    }
}
