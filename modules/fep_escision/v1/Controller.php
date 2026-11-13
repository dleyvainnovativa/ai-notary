<?php

namespace Modules\FepEscision\V1;

use App\Modules\ModuleInput;

require_once __DIR__ . '/../../fep/v1/Controller.php';

class Controller extends \Modules\Fep\V1\Controller
{
    public function inputs(): array
    {
        return [
            new ModuleInput(
                key: 'escritura',
                label: 'Escritura de escisión',
                required: true,
                promptPath: 'escision_prompt.txt',
                schemaPath: 'escision_schema.json',
                outputPath: 'escision_output.json',
                description: 'La escritura de escisión de sociedades.',
            ),
        ];
    }

    protected function moralBlockFields(bool $withAcciones = false): array
    {
        $f = [
            'denominacion_razon' => ['label' => 'Denominación o Razón Social', 'type' => 'text', 'col' => 'full', 'required' => true],
            'fecha_constitucion' => ['label' => 'Fecha de Constitución', 'type' => 'date', 'derive' => ['rule' => 'date_from_rfc_moral', 'rfc' => 'rfc']],
            'rfc' => ['label' => 'RFC', 'type' => 'text', 'validation' => ['format' => 'rfc']],
            'pais_nacionalidad' => ['label' => 'País de Nacionalidad', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_pais')],
            'giro_mercantil' => ['label' => 'Giro Mercantil', 'type' => 'select', 'col' => 'full', 'required' => true, 'options' => $this->cat('catalogo_giro_mercantil')],
            'capital_social_fijo' => ['label' => 'Capital Social Fijo', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true, 'money' => true],
            'capital_social_variable' => ['label' => 'Capital Social Variable', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'money' => true],
            'folio_mercantil' => ['label' => 'Folio Mercantil', 'type' => 'text'],
        ];
        if ($withAcciones) {
            $f['numero_total_acciones'] = ['label' => 'Número Total de Acciones', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true];
        }
        return $f;
    }

    protected function accionistaFragment(): array
    {
        $a = $this->personaFragment(['variant' => 'simple']);
        $a['itemSchema'] = array_merge($a['itemSchema'], ['numero_acciones' => ['label' => 'Número de Acciones', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true]]);
        return array_merge(['label' => 'Accionistas', 'type' => 'array', 'item_label' => 'Accionista', 'col' => 'full'], $a);
    }

    protected function activityFields(): array
    {
        return [
            'actividad' => [
                'label' => 'Escisión',
                'type' => 'object',
                'itemSchema' => [
                    'escindente' => [
                        'label' => 'Sociedad Escindente', 'type' => 'object', 'col' => 'full',
                        'itemSchema' => array_merge($this->moralBlockFields(false), [
                            'escindente_subsiste' => ['label' => '¿La escindente subsiste?', 'type' => 'select', 'col' => 'full', 'required' => true, 'options' => [['label' => 'No', 'value' => 'NO'], ['label' => 'Sí', 'value' => 'SI']]],
                            'accionistas' => $this->accionistaFragment(),
                        ]),
                    ],
                    'escindidas_determinadas' => ['label' => '¿Escindidas determinadas?', 'type' => 'select', 'col' => 'full', 'required' => true, 'options' => [['label' => 'No', 'value' => 'NO'], ['label' => 'Sí', 'value' => 'SI']]],
                    'escindidas' => [
                        'label' => 'Sociedades Escindidas', 'type' => 'array', 'item_label' => 'Escindida', 'col' => 'full',
                        'itemSchema' => array_merge($this->moralBlockFields(true), ['accionistas' => $this->accionistaFragment()]),
                    ],
                ],
            ],
        ];
    }

    protected function activitySubnav(): array
    {
        return [['field' => 'actividad.escindente', 'label' => 'Escindente'], ['field' => 'actividad.escindidas', 'label' => 'Escindidas']];
    }
}
