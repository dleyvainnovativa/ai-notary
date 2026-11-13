<?php

namespace Modules\FepModifpatrimonial\V1;

use App\Modules\ModuleInput;

require_once __DIR__ . '/../../fep/v1/Controller.php';

class Controller extends \Modules\Fep\V1\Controller
{
    public function inputs(): array
    {
        return [
            new ModuleInput(
                key: 'escritura',
                label: 'Escritura de modificación',
                required: true,
                promptPath: 'modifpatrimonial_prompt.txt',
                schemaPath: 'modifpatrimonial_schema.json',
                outputPath: 'modifpatrimonial_output.json',
                description: 'La escritura de modificación patrimonial de la persona moral.',
            ),
        ];
    }

    protected function activityFields(): array
    {
        $accionista = $this->personaFragment(['variant' => 'simple']);
        $accionista['itemSchema'] = array_merge(
            $accionista['itemSchema'],
            ['numero_acciones' => ['label' => 'Número de Acciones', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true]]
        );
        $capTipo = ['label' => '', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_tipo_modificacion_capital')];

        return [
            'actividad' => [
                'label' => 'Modificación Patrimonial',
                'type' => 'object',
                'itemSchema' => [
                    'persona_moral_modifica' => [
                        'label' => 'Persona Moral que se modifica', 'type' => 'object', 'col' => 'full',
                        'itemSchema' => [
                            'denominacion_razon' => ['label' => 'Denominación o Razón Social', 'type' => 'text', 'col' => 'full', 'required' => true],
                            'fecha_constitucion' => ['label' => 'Fecha de Constitución', 'type' => 'date', 'derive' => ['rule' => 'date_from_rfc_moral', 'rfc' => 'rfc']],
                            'rfc' => ['label' => 'RFC', 'type' => 'text', 'validation' => ['format' => 'rfc']],
                            'pais_nacionalidad' => ['label' => 'País de Nacionalidad', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_pais')],
                            'giro_mercantil' => ['label' => 'Giro Mercantil', 'type' => 'select', 'col' => 'full', 'required' => true, 'options' => $this->cat('catalogo_giro_mercantil')],
                            'numero_total_acciones' => ['label' => 'Número Total de Acciones', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true],
                            'motivo_modificacion' => ['label' => 'Motivo de Modificación', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_motivo_modificacion')],
                            'instrumento_publico' => ['label' => 'Instrumento Público', 'type' => 'text'],
                        ],
                    ],
                    'datos_modificacion' => [
                        'label' => 'Datos de la Modificación', 'type' => 'object', 'col' => 'full',
                        'itemSchema' => [
                            'tipo_modificacion_capital_fijo' => array_merge($capTipo, ['label' => 'Tipo modificación capital fijo']),
                            'inicial_capital_fijo' => ['label' => 'Capital fijo inicial', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true, 'money' => true],
                            'final_capital_fijo' => ['label' => 'Capital fijo final', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true, 'money' => true],
                            'tipo_modificacion_capital_variable' => array_merge($capTipo, ['label' => 'Tipo modificación capital variable']),
                            'inicial_capital_variable' => ['label' => 'Capital variable inicial', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true, 'money' => true],
                            'final_capital_variable' => ['label' => 'Capital variable final', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true, 'money' => true],
                            'accionistas' => array_merge(['label' => 'Accionistas', 'type' => 'array', 'item_label' => 'Accionista', 'col' => 'full'], $accionista),
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function activitySubnav(): array
    {
        return [['field' => 'actividad', 'label' => 'Modificación']];
    }
}
