<?php

namespace Modules\FepFusion\V1;

use App\Modules\ModuleInput;

require_once __DIR__ . '/../../fep/v1/Controller.php';

class Controller extends \Modules\Fep\V1\Controller
{
    public function inputs(): array
    {
        return [
            new ModuleInput(
                key: 'escritura',
                label: 'Escritura de fusión',
                required: true,
                promptPath: 'fusion_prompt.txt',
                schemaPath: 'fusion_schema.json',
                outputPath: 'fusion_output.json',
                description: 'La escritura de fusión de sociedades.',
            ),
        ];
    }

    /** Shared moral-company field set (denominación, capital, folio…). */
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
                'label' => 'Fusión',
                'type' => 'object',
                'itemSchema' => [
                    'tipo_fusion' => ['label' => 'Tipo de Fusión', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_tipo_fusion')],
                    'fusionadas' => ['label' => 'Sociedades Fusionadas', 'type' => 'array', 'item_label' => 'Fusionada', 'col' => 'full', 'itemSchema' => $this->moralBlockFields(false)],
                    'fusionante_determinadas' => ['label' => '¿Fusionante determinada?', 'type' => 'select', 'col' => 'full', 'required' => true, 'options' => [['label' => 'No', 'value' => 'NO'], ['label' => 'Sí', 'value' => 'SI']]],
                    'fusionante' => [
                        'label' => 'Sociedad Fusionante', 'type' => 'object', 'col' => 'full',
                        'itemSchema' => array_merge($this->moralBlockFields(true), ['accionistas' => $this->accionistaFragment()]),
                    ],
                ],
            ],
        ];
    }

    protected function activitySubnav(): array
    {
        return [['field' => 'actividad.fusionadas', 'label' => 'Fusionadas'], ['field' => 'actividad.fusionante', 'label' => 'Fusionante']];
    }
}
