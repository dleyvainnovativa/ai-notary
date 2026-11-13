<?php

namespace Modules\FepCompraventa\V1;

use App\Modules\ModuleInput;

require_once __DIR__ . '/../../fep/v1/Controller.php';

class Controller extends \Modules\Fep\V1\Controller
{
    public function inputs(): array
    {
        return [
            new ModuleInput(
                key: 'escritura',
                label: 'Escritura de compraventa de acciones',
                required: true,
                promptPath: 'compraventa_prompt.txt',
                schemaPath: 'compraventa_schema.json',
                outputPath: 'compraventa_output.json',
                description: 'La escritura de compraventa de acciones o partes sociales.',
            ),
        ];
    }

    protected function parteFragment(string $itemLabel): array
    {
        $p = $this->personaFragment(['variant' => 'simple']);
        $p['itemSchema'] = array_merge($p['itemSchema'], ['numero_acciones' => ['label' => 'Número de Acciones', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true]]);
        return array_merge(['type' => 'array', 'item_label' => $itemLabel, 'col' => 'full'], $p);
    }

    protected function activityFields(): array
    {
        return [
            'actividad' => [
                'label' => 'Compraventa de Acciones',
                'type' => 'object',
                'itemSchema' => [
                    'tipo_operacion' => ['label' => 'Tipo de Operación', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_tipo_operacion')],
                    'sociedades' => [
                        'label' => 'Sociedades (emisoras)', 'type' => 'array', 'item_label' => 'Sociedad', 'col' => 'full',
                        'itemSchema' => [
                            'denominacion_razon' => ['label' => 'Denominación o Razón Social', 'type' => 'text', 'col' => 'full', 'required' => true],
                            'fecha_constitucion' => ['label' => 'Fecha de Constitución', 'type' => 'date', 'derive' => ['rule' => 'date_from_rfc_moral', 'rfc' => 'rfc']],
                            'rfc' => ['label' => 'RFC', 'type' => 'text', 'validation' => ['format' => 'rfc']],
                            'pais_nacionalidad' => ['label' => 'País de Nacionalidad', 'type' => 'select', 'required' => true, 'options' => $this->cat('catalogo_pais')],
                            'valor_nominal' => ['label' => 'Valor Nominal', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true, 'money' => true],
                            'numero_acciones' => ['label' => 'Número de Acciones', 'type' => 'number', 'format' => 'decimal', 'min' => 0, 'required' => true],
                            'vendedores' => array_merge(['label' => 'Vendedores'], $this->parteFragment('Vendedor')),
                            'compradores' => array_merge(['label' => 'Compradores'], $this->parteFragment('Comprador')),
                        ],
                    ],
                    'liquidaciones' => [
                        'label' => 'Liquidaciones', 'type' => 'array', 'item_label' => 'Liquidación', 'col' => 'full',
                        'itemSchema' => [
                            'fecha_pago' => ['label' => 'Fecha de Pago', 'type' => 'date', 'required' => true],
                            'instrumento_monetario' => ['label' => 'Instrumento Monetario', 'type' => 'select', 'options' => $this->cat('catalogo_instrumento_monetario')],
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
        return [['field' => 'actividad.sociedades', 'label' => 'Sociedades'], ['field' => 'actividad.liquidaciones', 'label' => 'Liquidaciones']];
    }
}
