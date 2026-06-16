<?php

namespace Modules\Declaranot\V1;

use App\Modules\ModuleControllerContract;
use App\Modules\ModuleInput;

class Controller implements ModuleControllerContract
{
    public function __construct(private string $dir) {}

    public function inputs(): array
    {
        return [
            new ModuleInput(
                key: 'escritura',
                label: 'Escritura pública',
                required: true,
                promptPath: 'declaranot_prompt.txt',
                schemaPath: 'declaranot_schema.json',
                outputPath: 'declaranot_output.json',
                description: 'El documento de escritura notariado. Esta es la fuente principal para la extracción.',
            ),
            new ModuleInput(
                key: 'calculo',
                label: 'Guía de cálculo de pagos',
                required: false,
                promptPath: 'calculo_pagos_prompt.txt',
                schemaPath: 'calculo_pagos_schema.json',
                outputPath: 'calculo_pagos_output.json',
                description: 'Opcional. La guía de cálculo de ISR/pagos. Agrega datos de impuestos y propiedad compartida.',
            ),
        ];
    }

    public function schema(): array
    {
        $escritura = json_decode(file_get_contents($this->dir . '/declaranot_schema.json'), true);
        $calculo   = json_decode(file_get_contents($this->dir . '/calculo_pagos_schema.json'), true);

        return [
            'schema_version' => '1.0.0',
            'fields' => array_merge($escritura['fields'], $calculo['fields']),
        ];
    }

    public function postProcess(array $merged): array
    {
        // Module-specific cleanup on the merged, confidence-wrapped output.
        // (Phase 5 handles computed/derived/conditional via the schema engine.)
        return $merged;
    }
    public function formSchema(): array
    {
        $cat = fn($name) => app(\App\Services\CatalogService::class)->load($name, $this->dir) ?? [];

        $catalogInmuebles        = $cat('catalogo_inmuebles');
        $catalogTiposPago        = $cat('catalogo_tipos_pago');
        $catalogBancos           = $cat('catalogo_bancos');
        $catalogTiposDomicilio   = $cat('catalogo_tipos_domicilio');
        $catalogNacionalidad   = $cat('catalogo_nacionalidad');
        $catalogDatosInformativos = $cat('catalogo_datos_informativos');

        $fields = [
            'numero_escritura' => ['label' => 'Número de Escritura', 'type' => 'text', 'required' => true],
            'fecha_firma_escritura' => ['label' => 'Fecha de Firma', 'type' => 'date', 'required' => true],
            'tipo_inmueble' => ['label' => 'Tipo de Inmueble', 'type' => 'select', 'required' => true, 'options' => $catalogInmuebles],
            'especifica_inmueble' => ['label' => 'Especificar Inmueble', 'type' => 'text', 'required_if' => ['tipo_inmueble' => '9']],
            'avaluo_inmueble' => ['label' => 'Avalúo del Inmueble', "description" => "Mapear.", 'type' => 'number', 'format' => 'round', 'integer' => true, 'required' => true],

            'pagos_inmueble' => [
                'label' => 'Pagos del Inmueble',
                'type' => 'array',
                'itemSchema' => [
                    'monto' => ['label' => 'Monto', 'type' => 'number', 'format' => 'round', 'integer' => true, 'required' => true],
                    'tipo_pago_inmueble' => ['label' => 'Tipo de Pago', 'type' => 'select', 'required' => true, 'options' => $catalogTiposPago],
                    'otro_pago' => ['label' => 'Otro tipo de pago (especificar)', 'type' => 'text', 'required_if' => ['tipo_pago_inmueble' => ['9']]],
                    'institucion_financiera' => ['label' => 'Institución Financiera', 'type' => 'select', 'options' => $catalogBancos, 'required_if' => ['tipo_pago_inmueble' => ['2', '3']]],
                    'numero_cuenta' => ['label' => 'Número de Cuenta', 'type' => 'text', 'required_if' => ['tipo_pago_inmueble' => ['2', '3']]],
                    'otro' => ['label' => 'Otro (especificar)', 'type' => 'text', 'required_if' => ['institucion_financiera' => ['999']]],
                ],
            ],

            'enajenantes' => [
                'label' => 'Enajenantes',
                'type' => 'array',
                'classifier' => ['rule' => 'persona_case', 'tipo_field' => 'tipo_enajenante', 'rfc_field' => 'rfc'],
                'legend' => 'Para extranjeros use un RFC genérico: EXTF900101000 (persona física) o EXT990101000 (persona moral).',
                'itemSchema' => [
                    'tipo_enajenante' => ['label' => 'Tipo', 'type' => 'select', 'required' => true, 'options' => $catalogTiposDomicilio],
                    'rfc' => ['label' => 'RFC', 'type' => 'text', 'required' => true, 'validation' => ['format' => 'rfc']],
                    'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required_in_cases' => ['nacional_fisica', 'extranjera_fisica']],
                    'apellido_paterno' => ['label' => 'Apellido Paterno', 'type' => 'text', 'show_in_cases' => ['nacional_fisica', 'extranjera_fisica']],
                    'apellido_materno' => ['label' => 'Apellido Materno', 'type' => 'text', 'show_in_cases' => ['nacional_fisica', 'extranjera_fisica']],
                    'curp' => ['label' => 'CURP', 'type' => 'text', 'validation' => ['format' => 'curp'], 'required_in_cases' => ['nacional_fisica']],
                    'razon_social' => ['label' => 'Razón Social', 'type' => 'text', 'col' => 'full', 'required_in_cases' => ['nacional_moral', 'extranjera_moral']],
                    'nacionalidad' => ['label' => 'Nacionalidad', 'type' => 'select', 'options' => $catalogNacionalidad, 'required_in_cases' => ['extranjera_fisica', 'extranjera_moral']],
                    'fecha_nacimiento' => ['label' => 'Fecha de Nacimiento', 'type' => 'date', 'required_in_cases' => ['extranjera_fisica']],
                    'documento_oficial' => ['label' => 'Documento Oficial', 'type' => 'text', 'required_in_cases' => ['extranjera_fisica']],
                    'folio' => ['label' => 'Número de Folio', 'type' => 'text', 'required_in_cases' => ['extranjera_fisica']],
                ],
            ],

            // 'enajenantes' => [
            //     'label' => 'Enajenantes',
            //     "subtitle" => "Mapear.",
            //     'type' => 'array',
            //     'itemSchema' => [
            //         'tipo_enajenante' => ['label' => 'Tipo', 'type' => 'select', 'required' => true, 'options' => $catalogTiposDomicilio],
            //         'rfc' => ['label' => 'RFC',  'type' => 'text', 'required' => true, 'validation' => ['format' => 'rfc']],
            //         'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required' => true],
            //         'apellido_paterno' => ['label' => 'Apellido Paterno', 'type' => 'text'],
            //         'apellido_materno' => ['label' => 'Apellido Materno', 'type' => 'text'],
            //         'curp' => ['label' => 'CURP', 'type' => 'text', 'required_if' => ['tipo_enajenante' => '1'], 'validation' => ['format' => 'curp']],
            //         'razon_social' => [
            //             'label' => 'Razón Social',
            //             'type' => 'text',
            //             'col' => 'full',
            //             'required_when' => ['field' => 'rfc', 'rule' => 'rfc_invalid_or_generic_moral'],
            //             'subtitle' => 'Obligatorio cuando el RFC no es válido o es genérico extranjero de persona moral.',
            //         ],
            //         'nacionalidad' => [
            //             'label' => 'Nacionalidad',
            //             'type' => 'select',
            //             'options' => $catalogNacionalidad,   // add: $catalogNacionalidad = $cat('catalogo_nacionalidad');
            //             'required_if' => ['tipo_enajenante' => '2'],
            //         ],
            //         'fecha_nacimiento' => [
            //             'label' => 'Fecha de Nacimiento',
            //             'type' => 'date',
            //             'required_if' => ['tipo_enajenante' => '2'],
            //         ],
            //         'documento_oficial' => [
            //             'label' => 'Documento Oficial',
            //             'type' => 'text',
            //             'required_if' => ['tipo_enajenante' => '2'],
            //         ],
            //         'folio' => [
            //             'label' => 'Número de Folio',
            //             'type' => 'text',
            //             'required_if' => ['tipo_enajenante' => '2'],
            //         ],
            //     ],
            // ],

            'datos_informativos' => [
                'label' => 'Datos Informativos',
                'type' => 'object',
                'itemSchema' => [
                    'ingresos_exentos' => ['label' => 'Ingresos Exentos', 'type' => 'select', "description" => "Mapear.", 'options' => $catalogDatosInformativos, 'default_if_missing_label' => 'No'],
                    'monto' => ['label' => 'Monto', 'type' => 'number', 'format' => 'round', 'integer' => true, 'required_if' => ['ingresos_exentos' => '1']],
                    'impuesto' => ['label' => 'Impuesto', 'type' => 'number', 'format' => 'round', 'integer' => true, 'required_if' => ['ingresos_exentos' => '1']],
                ],
            ],

            'adquirientes' => [
                'label' => 'Adquirientes',
                'type' => 'array',
                'classifier' => ['rule' => 'persona_case', 'tipo_field' => 'tipo_adquiriente', 'rfc_field' => 'rfc'],
                'legend' => 'Para extranjeros use un RFC genérico: EXTF900101000 (persona física) o EXT990101000 (persona moral).',
                'itemSchema' => [
                    'tipo_adquiriente' => ['label' => 'Tipo', 'type' => 'select', 'required' => true, 'options' => $catalogTiposDomicilio],
                    'rfc' => ['label' => 'RFC', 'type' => 'text', 'required' => true, 'validation' => ['format' => 'rfc']],
                    'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required_in_cases' => ['nacional_fisica', 'extranjera_fisica']],
                    'apellido_paterno' => ['label' => 'Apellido Paterno', 'type' => 'text', 'show_in_cases' => ['nacional_fisica', 'extranjera_fisica']],
                    'apellido_materno' => ['label' => 'Apellido Materno', 'type' => 'text', 'show_in_cases' => ['nacional_fisica', 'extranjera_fisica']],
                    'curp' => ['label' => 'CURP', 'type' => 'text', 'validation' => ['format' => 'curp'], 'required_in_cases' => ['nacional_fisica']],
                    'razon_social' => ['label' => 'Razón Social', 'type' => 'text', 'col' => 'full', 'required_in_cases' => ['nacional_moral', 'extranjera_moral']],
                    'nacionalidad' => ['label' => 'Nacionalidad', 'type' => 'select', 'options' => $catalogNacionalidad, 'required_in_cases' => ['extranjera_fisica', 'extranjera_moral']],
                    'fecha_nacimiento' => ['label' => 'Fecha de Nacimiento', 'type' => 'date', 'required_in_cases' => ['extranjera_fisica']],
                    'documento_oficial' => ['label' => 'Documento Oficial', 'type' => 'text', 'required_in_cases' => ['extranjera_fisica']],
                    'folio' => ['label' => 'Número de Folio', 'type' => 'text', 'required_in_cases' => ['extranjera_fisica']],
                ],
            ],

            // 'adquirientes' => [
            //     'label' => 'Adquirientes',
            //     "subtitle" => "Mapear.",
            //     'type' => 'array',
            //     'itemSchema' => [
            //         'tipo_adquiriente' => ['label' => 'Tipo', 'type' => 'select', "description" => "Mapear.", 'options' => $catalogTiposDomicilio],
            //         'rfc' => ['label' => 'RFC', 'type' => 'text', 'required' => true, 'validation' => ['format' => 'rfc']],
            //         'nombre' => ['label' => 'Nombre', 'type' => 'text'],
            //         'apellido_paterno' => ['label' => 'Apellido Paterno', 'type' => 'text'],
            //         'apellido_materno' => ['label' => 'Apellido Materno', 'type' => 'text'],
            //         'curp' => ['label' => 'CURP', 'type' => 'text', 'required_if' => ['tipo_adquiriente' => '1'], 'validation' => ['format' => 'curp']],
            //         'razon_social' => [
            //             'label' => 'Razón Social',
            //             'type' => 'text',
            //             'col' => 'full',
            //             'required_when' => ['field' => 'rfc', 'rule' => 'rfc_invalid_or_generic_moral'],
            //             'subtitle' => 'Obligatorio cuando el RFC no es válido o es genérico extranjero de persona moral.',
            //         ],
            //         'nacionalidad' => [
            //             'label' => 'Nacionalidad',
            //             'type' => 'select',
            //             'options' => $catalogNacionalidad,   // add: $catalogNacionalidad = $cat('catalogo_nacionalidad');
            //             'required_if' => ['tipo_adquiriente' => '2'],
            //         ],
            //         'fecha_nacimiento' => [
            //             'label' => 'Fecha de Nacimiento',
            //             'type' => 'date',
            //             'required_if' => ['tipo_adquiriente' => '2'],
            //         ],
            //         'documento_oficial' => [
            //             'label' => 'Documento Oficial',
            //             'type' => 'text',
            //             'required_if' => ['tipo_adquiriente' => '2'],
            //         ],
            //         'folio' => [
            //             'label' => 'Número de folio',
            //             'type' => 'text',
            //             'required_if' => ['tipo_adquiriente' => '2'],
            //         ],
            //     ],
            // ],

            'pago' => [
                'label' => 'Pago',
                'type' => 'array',
                'itemSchema' => [
                    'ingresos_enajenacion' => ['label' => 'Ingresos por Enajenación', 'required' => true, 'type' => 'number', 'format' => 'round', 'integer' => true],
                    'ingresos_exentos' => ['label' => 'Ingresos Exentos', 'required' => true, 'type' => 'number', 'format' => 'round', 'integer' => true],
                    'ingreso_sismo_2017' => ['label' => 'Ingreso Sismo 2017', 'type' => 'number', 'format' => 'round', 'integer' => true],
                    'deducciones_autorizadas' => ['label' => 'Deducciones Autorizadas', 'required' => true, 'type' => 'number', 'format' => 'round', 'integer' => true],
                    'ganancia_perdida' => ['label' => 'Ganancia / Pérdida', 'required' => true, 'type' => 'number', 'format' => 'round', 'integer' => true],
                    'years_adquisicion_venta' => ['label' => 'Años entre Adquisición y Venta', 'required' => true, 'type' => 'number', 'max' => 20, 'format' => 'round', 'integer' => true],
                    'ganancia_acumulable' => ['label' => 'Ganancia Acumulable', 'required' => true, 'type' => 'number', 'format' => 'round', 'integer' => true],
                    'ganancia_no_acumulable' => ['label' => 'Ganancia No Acumulable', 'required' => true, 'type' => 'number', 'format' => 'round', 'integer' => true],
                    'isr_federacion' => ['label' => 'ISR Federación', 'type' => 'number', 'required' => true, 'format' => 'round', 'integer' => true],
                    'numero_operacion_federacion' => ['label' => 'Número de Operación Federación', 'type' => 'number', 'format' => 'round', 'integer' => true],
                    'fecha_pago_federacion' => ['label' => 'Fecha de Pago Federación', 'type' => 'date'],
                    'isr_entidad' => ['label' => 'ISR Entidad', 'required' => true, 'type' => 'number', 'format' => 'round', 'integer' => true],
                    'numero_operacion_entidad' => ['label' => 'Número de Operación Entidad', 'required_if' => ['isr_entidad' => ['op' => '>', 'value' => 0]], 'type' => 'number', 'format' => 'round', 'integer' => true],
                    'fecha_pago_entidad' => ['label' => 'Fecha de Pago Entidad', 'required_if' => ['isr_entidad' => ['op' => '>', 'value' => 0]], 'type' => 'date'],
                    'total_isr_pagado' => ['label' => 'Total ISR Pagado', 'type' => 'computed', 'formula' => 'isr_federacion + isr_entidad'],
                ],
            ],

            'copropiedad' => [
                'label' => 'Copropiedad',
                'type' => 'object',
                'itemSchema' => [
                    'existe_copropiedad' => ['label' => '¿Existe Copropiedad?', 'type' => 'select', 'options' => $catalogDatosInformativos, 'default_if_missing_label' => 'No', 'derive_from_array_length' => ['path' => 'copropiedad.integrantes', 'if_gt' => 0, 'true_label' => 'Sí', 'false_label' => 'No']],
                    'integrantes' => [
                        'label' => 'Integrantes',
                        'col' => 'full',
                        'type' => 'array',
                        'enabled_if' => ['existe_copropiedad' => '1'],
                        'itemSchema' => [
                            'rfc' => ['label' => 'RFC', 'type' => 'text'],
                            'porcentaje' => ['label' => 'Porcentaje (%)', 'type' => 'number', 'format' => 'round', 'integer' => true],
                            'ingresos_enajenacion' => ['label' => 'Ingresos por Enajenación', 'type' => 'number', 'format' => 'round', 'integer' => true],
                            'deducciones_autorizadas' => ['label' => 'Deducciones Autorizadas', 'type' => 'number', 'format' => 'round', 'integer' => true],
                            'ganancia_perdida' => ['label' => 'Ganancia / Pérdida', 'type' => 'number', 'format' => 'round', 'integer' => true],
                            'ganancia_acumulable' => ['label' => 'Ganancia Acumulable', 'type' => 'number', 'format' => 'round', 'integer' => true],
                            'ganancia_no_acumulable' => ['label' => 'Ganancia No Acumulable', 'type' => 'number', 'format' => 'round', 'integer' => true],
                            'isr_federacion' => ['label' => 'ISR Federación', 'type' => 'number', 'format' => 'round', 'integer' => true],
                            'isr_entidad' => ['label' => 'ISR Entidad', 'type' => 'number', 'format' => 'round', 'integer' => true],
                        ],
                    ],
                ],
            ],

            'representante_comun' => [
                'label' => 'Representante Común',
                'type' => 'object',
                'itemSchema' => [
                    'existe_representante_comun' => ['label' => '¿Existe Representante Común?', 'type' => 'select', 'options' => $catalogDatosInformativos, 'default_if_missing_label' => 'No'],
                    'rfc_representante' => ['label' => 'RFC del Representante', 'type' => 'text', 'required_if' => ['existe_representante_comun' => '1']],
                ],
            ],
        ];

        // Section grouping matching the reference UI
        $sections = [
            ['title' => 'Información General', 'subtitle' => 'Datos generales del inmueble', 'fields' => ['numero_escritura', 'fecha_firma_escritura', 'tipo_inmueble', 'especifica_inmueble', 'avaluo_inmueble']],
            ['title' => 'Pagos del Inmueble', 'fields' => ['pagos_inmueble']],
            ['title' => 'Enajenantes', 'fields' => ['enajenantes']],
            ['title' => 'Datos Informativos', 'fields' => ['datos_informativos']],
            ['title' => 'Adquirientes', 'fields' => ['adquirientes']],
            ['title' => 'Pago', 'fields' => ['pago']],
            ['title' => 'Copropiedad', 'fields' => ['copropiedad']],
            ['title' => 'Representante Común', 'fields' => ['representante_comun']],
        ];

        return ['fields' => $fields, 'sections' => $sections];
    }
}
