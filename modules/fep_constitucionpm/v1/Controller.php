<?php

namespace Modules\FepConstitucionpm\V1;

use App\Modules\ModuleInput;

require_once __DIR__ . '/../../fep/v1/Controller.php';

class Controller extends \Modules\Fep\V1\Controller
{
    public function inputs(): array
    {
        return [
            new ModuleInput(
                key: 'escritura',
                label: 'Escritura constitutiva',
                required: true,
                promptPath: 'constitucionpm_prompt.txt',
                schemaPath: 'constitucionpm_schema.json',
                outputPath: 'constitucionpm_output.json',
                description: 'La escritura de constitución de la persona moral.',
            ),
        ];
    }

    protected function activityFields(): array
    {
        $accionista = $this->personaFragment(['variant' => 'simple']);
        $accionista['itemSchema'] = array_merge(
            ['cargo_accionista' => ['label' => 'Cargo del Accionista', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_cargo_accionista')]],
            $accionista['itemSchema'],
            ['numero_acciones' => ['label' => 'Número de Acciones', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true]]
        );

        return [
            'actividad' => [
                'label' => 'Constitución de Persona Moral',
                'type' => 'object',
                'itemSchema' => [
                    'tipo_persona_moral' => ['label' => 'Tipo de Persona Moral', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_tipo_persona_moral')],
                    'tipo_persona_moral_otra' => ['label' => 'Especifique (si "Otra")', 'type' => 'text', 'col' => 'full', 'required_if' => ['tipo_persona_moral' => '99']],
                    'denominacion_razon' => ['label' => 'Denominación o Razón Social', 'type' => 'text', 'col' => 'full', 'required' => true],
                    'giro_mercantil' => ['label' => 'Giro Mercantil u Objeto Social', 'type' => 'select', 'col' => 'full', 'required' => true, 'options' => $this->cat('catalogo_giro_mercantil')],
                    'folio_mercantil' => ['label' => 'Folio Mercantil', 'type' => 'text'],
                    'numero_total_acciones' => ['label' => 'Número Total de Acciones/Partes', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true],
                    'entidad_federativa' => ['label' => 'Entidad Federativa', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_entidad_federativa')],
                    'consejo_vigilancia' => ['label' => '¿Tiene consejo de vigilancia?', 'type' => 'select', 'required' => true, 'options' => [['label' => 'No', 'value' => 'NO'], ['label' => 'Sí', 'value' => 'SI']]],
                    'motivo_constitucion' => ['label' => 'Motivo de Constitución', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_motivo_constitucion')],
                    'instrumento_publico' => ['label' => 'Instrumento Público (constitución)', 'type' => 'text'],
                    'accionistas' => array_merge(['label' => 'Accionistas / Socios', 'type' => 'array', 'item_label' => 'Accionista', 'col' => 'full'], $accionista),
                    'capital_social' => [
                        'label' => 'Capital Social', 'type' => 'object',
                        'itemSchema' => [
                            'capital_fijo' => ['label' => 'Capital Fijo', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true, 'money' => true],
                            'capital_variable' => ['label' => 'Capital Variable', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'money' => true],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function activitySubnav(): array
    {
        return [['field' => 'actividad', 'label' => 'Sociedad'], ['field' => 'actividad.accionistas', 'label' => 'Accionistas']];
    }
}
