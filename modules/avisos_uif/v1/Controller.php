<?php

namespace Modules\AvisosUif\V1;

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
                promptPath: 'uif_prompt.txt',
                schemaPath: 'uif_schema.json',
                outputPath: 'uif_output.json',
                description: 'La escritura notarial de la operación a reportar.',
            ),
        ];
    }

    public function schema(): array
    {
        return json_decode(file_get_contents($this->dir . '/uif_schema.json'), true);
    }

    public function formSchema(): array
    {
        $cat = fn($name) => app(\App\Services\CatalogService::class)->load($name, $this->dir) ?? [];

        $catPrioridad     = $cat('catalogo_prioridad');
        $catTiposAlerta    = $cat('catalogo_tipos_alerta');
        $catOpsAcumuladas  = $cat('catalogo_operaciones_acumuladas');
        $catTipoTransmision = $cat('catalogo_tipo_transmision');
        $catActividad      = $cat('catalogo_actividad_economica');
        $catGiro           = $cat('catalogo_giro_mercantil');
        $catPersona        = $cat('catalogo_persona');            // 1=física, 2=moral, 3=fideicomiso
        $catDomicilio      = $cat('catalogo_domicilio');            // nacional / extranjero
        $catNacionalidad   = $cat('catalogo_nacionalidad');        // país (MX, US, ...)
        $catEntidades      = $cat('catalogo_entidades_federativas');
        $catTiposDomicilio = $cat('catalogo_tipos_domicilio');
        $catFormasPago     = $cat('catalogo_formas_de_pago');
        $catInstrumentos   = $cat('catalogo_instrumentos_monetarios');
        $catMonedas        = $cat('catalogo_tipos_moneda');

        /* ---------- Reusable domicilio block ---------- */
        $domicilio = fn() => [
            'label' => 'Domicilio Particular',
            'type' => 'object',
            'itemSchema' => [
                'tipo_domicilio' => [
                    'label' => '¿Domicilio nacional o extranjero?',
                    'type' => 'select',
                    'required' => true,
                    'options' => $catDomicilio,
                ],
                'entidad_federativa' => [
                    'label' => 'Entidad Federativa',
                    'type' => 'select',
                    'required' => true,
                    'options' => $catEntidades,
                ],
                'calle' => ['label' => 'Calle', 'type' => 'text', 'col' => 'full', 'required' => true],
                'num_ext' => ['label' => 'Núm. Ext.', 'type' => 'text', 'required' => true],
                'num_int' => ['label' => 'Núm. Int.', 'type' => 'text'],
                'codigo_postal' => [
                    'label' => 'C.P.',
                    'type' => 'text',
                    'required' => true,
                    'cp_lookup' => 'colonia',   // marks this CP as driving the 'colonia' select in the same scope
                    'subtitle' => 'Escribe el CP para cargar las colonias.',
                ],
                'colonia' => [
                    'label' => 'Colonia',
                    'type' => 'select',
                    'required' => true,
                    'options' => [],            // populated by CP lookup (next step)
                    'cp_target' => true,        // marks this as the select the CP fills
                    'subtitle' => 'Selecciona la Colonia cargada del CP.'
                ],
                'municipio' => ['label' => 'Municipio', 'type' => 'text', 'required' => true],
            ],
        ];

        /* ---------- Reusable persona-con-domicilio (adquirente / vendedor) ----------
         * Cases come from the user-selected tipo_persona via the
         * 'persona_tipo_select' classifier rule:
         *   1 => 'fisica', 2 => 'moral', 3 => 'fideicomiso'
         * razon_social is reused for moral's razón social AND fideicomiso's
         * fiduciario name.
         */
        $personaConDomicilio = fn($tipoLabel) => [
            'tipo_persona' => [
                'label' => 'Tipo de Persona',
                'type' => 'select',
                'required' => true,
                'options' => $catPersona,
            ],

            // --- Persona Física ---
            'rfc' => ['label' => 'RFC', 'type' => 'text', 'validation' => ['format' => 'rfc'], 'required_in_cases' => ['fisica', 'moral', 'fideicomiso']],
            'curp' => ['label' => 'CURP', 'type' => 'text', 'validation' => ['format' => 'curp'], 'required_in_cases' => ['fisica']],
            'fecha_nacimiento' => ['label' => 'Fecha de Nacimiento', 'type' => 'date', 'required_in_cases' => ['fisica']],
            'nombre' => ['label' => 'Nombre(s)', 'type' => 'text', 'required_in_cases' => ['fisica']],
            'apellido_paterno' => ['label' => 'Apellido Paterno', 'type' => 'text', 'required_in_cases' => ['fisica']],
            'apellido_materno' => ['label' => 'Apellido Materno', 'type' => 'text', 'show_in_cases' => ['fisica']],
            'actividad_economica' => ['label' => 'Actividad Económica u Ocupación', 'type' => 'select', 'col' => 'full', 'options' => $catActividad, 'required_in_cases' => ['fisica']],

            // --- Persona Moral (razon_social reused; also used by fideicomiso) ---
            'razon_social' => ['label' => 'Denominación o Razón Social', 'type' => 'text', 'col' => 'full', 'required_in_cases' => ['moral', 'fideicomiso'], 'subtitle' => 'Para fideicomiso: razón social del fiduciario.'],
            'fecha_constitucion' => ['label' => 'Fecha de Constitución', 'type' => 'date', 'required_in_cases' => ['moral']],
            'giro_mercantil' => ['label' => 'Actividad Económica, Giro Mercantil u Objeto Social', 'type' => 'select', 'col' => 'full', 'options' => $catGiro, 'required_in_cases' => ['moral']],

            // --- Fideicomiso ---
            'numero_fideicomiso' => ['label' => 'Número, Referencia o Identificador del Fideicomiso', 'type' => 'text', 'col' => 'full', 'required_in_cases' => ['fideicomiso']],

            // --- País de nacionalidad (física + moral; not fideicomiso per manual) ---
            'nacionalidad' => ['label' => 'País de Nacionalidad', 'type' => 'select', 'options' => $catNacionalidad, 'required_in_cases' => ['fisica', 'moral']],

            // --- Domicilio (all cases) ---
            'domicilio' => $domicilio(),
        ];

        $fields = [
            /* ===== Datos del Aviso ===== */
            'referencia_aviso' => ['label' => 'Referencia del Aviso', 'type' => 'text', 'required' => true],
            'prioridad' => ['label' => 'Prioridad', 'type' => 'select', 'required' => true, 'options' => $catPrioridad],
            'tipo_alerta' => ['label' => 'Tipo Alerta', 'type' => 'select', 'required' => true, 'options' => $catTiposAlerta],
            'descripcion_alerta' => ['label' => 'Descripción de alerta', 'type' => 'text', 'col' => 'full', 'required_if' => ['tipo_alerta' => '9999']],

            /* ===== Persona que solicita la formalización ===== */
            'solicitante' => [
                'label' => 'Persona que solicita la formalización',
                'type' => 'object',
                'itemSchema' => [
                    'rfc' => ['label' => 'RFC', 'type' => 'text', 'required' => true, 'validation' => ['format' => 'rfc']],
                    'curp' => ['label' => 'CURP', 'type' => 'text', 'required' => true, 'validation' => ['format' => 'curp']],
                    'fecha_nacimiento' => ['label' => 'Fecha Nac.', 'type' => 'date'],
                    'nombre' => ['label' => 'Nombre', 'type' => 'text', 'required' => true],
                    'apellido_paterno' => ['label' => 'Apellido Paterno', 'type' => 'text', 'required' => true],
                    'apellido_materno' => ['label' => 'Apellido Materno', 'type' => 'text'],
                ],
            ],

            /* ===== Detalle de la Operación ===== */
            'operaciones_acumuladas' => [
                'label' => '¿Desea agregar operaciones acumuladas?',
                'type' => 'select',
                'options' => $catOpsAcumuladas,
            ],

            'operaciones' => [
                'label' => 'Operaciones',
                'type' => 'array',
                'itemSchema' => [
                    'fecha_operacion' => ['label' => 'Fecha Operación', 'type' => 'date', 'required' => true],
                    'tipo_transmision' => ['label' => 'Tipo Transmisión', 'type' => 'select', 'required' => true, 'options' => $catTipoTransmision],

                    'adquirentes' => [
                        'col' => 'full',
                        'label' => 'Adquirentes',
                        'type' => 'array',
                        'classifier' => ['rule' => 'persona_tipo_select', 'tipo_field' => 'tipo_persona'],
                        'legend' => 'Para extranjeros use un RFC genérico: EXTF900101000 (física) o EXT990101000 (moral).',
                        'itemSchema' => $personaConDomicilio('Adquirente'),
                    ],

                    'vendedores' => [
                        'col' => 'full',
                        'label' => 'Vendedores',
                        'type' => 'array',
                        'classifier' => ['rule' => 'persona_tipo_select', 'tipo_field' => 'tipo_persona'],
                        'legend' => 'Para extranjeros use un RFC genérico: EXTF900101000 (física) o EXT990101000 (moral).',
                        'itemSchema' => $personaConDomicilio('Vendedor'),
                    ],

                    'inmueble' => [
                        'label' => 'Inmueble',
                        'type' => 'object',
                        'itemSchema' => [
                            'tipo_bien' => ['label' => 'Tipo Bien', 'type' => 'select', 'required' => true, 'options' => $catTiposDomicilio],
                            'valor_pactado' => ['label' => 'Valor Pactado', 'type' => 'number', 'format' => 'round', 'integer' => true, 'min' => 0, 'required' => true],
                            'm2_terreno' => ['label' => 'M2 Terreno', 'type' => 'number', 'format' => 'round', 'integer' => true, 'min' => 0, 'required' => true],
                            'm2_construidos' => ['label' => 'M2 Construidos', 'type' => 'number', 'format' => 'round', 'integer' => true, 'min' => 0, 'required' => true],
                            'folio_real' => ['label' => 'Folio Real', 'type' => 'text', 'required' => true],
                            'num_instrumento' => ['label' => 'Núm. Instrumento', 'type' => 'text', 'required' => true],
                            'valor_avaluo' => ['label' => 'Valor Avalúo', 'type' => 'number', 'format' => 'round', 'integer' => true, 'min' => 0, 'required' => true],
                            'domicilio' => [
                                'label' => 'Domicilio del Inmueble',
                                'type' => 'object',
                                'itemSchema' => [
                                    'entidad_federativa' => ['label' => 'Entidad', 'type' => 'select', 'required' => true, 'options' => $catEntidades],
                                    'calle' => ['label' => 'Calle', 'type' => 'text', 'col' => 'full', 'required' => true],
                                    'num_ext' => ['label' => 'Núm. Ext.', 'type' => 'text', 'required' => true],
                                    'num_int' => ['label' => 'Núm. Int.', 'type' => 'text'],
                                    'codigo_postal' => ['label' => 'C.P.', 'type' => 'text', 'required' => true, 'cp_lookup' => 'colonia', 'subtitle' => 'Escribe el CP para cargar las colonias.'],
                                    'colonia' => ['label' => 'Colonia', 'type' => 'select', 'required' => true, 'options' => [], 'cp_target' => true, 'subtitle' => 'Selecciona la Colonia cargada del CP.'],
                                    'municipio' => ['label' => 'Municipio', 'type' => 'text', 'required' => true],
                                ],
                            ],
                        ],
                    ],

                    'pagos' => [
                        'label' => 'Liquidaciones / Pagos',
                        'type' => 'array',
                        'col' => 'full',
                        'itemSchema' => [
                            'fecha_pago' => ['label' => 'Fecha Pago', 'type' => 'date', 'required' => true],
                            'forma_pago' => ['label' => 'Forma Pago', 'type' => 'select', 'required' => true, 'options' => $catFormasPago],
                            'instrumento' => ['label' => 'Instrumento', 'type' => 'select', 'required' => true, 'options' => $catInstrumentos],
                            'moneda' => ['label' => 'Moneda', 'type' => 'select', 'required' => true, 'options' => $catMonedas],
                            'monto' => ['label' => 'Monto', 'type' => 'number', 'format' => 'round', 'integer' => true, 'min' => 0, 'required' => true],
                        ],
                    ],
                ],
            ],
        ];

        $sections = [
            ['title' => 'Datos del Aviso', 'fields' => ['referencia_aviso', 'prioridad', 'tipo_alerta', 'descripcion_alerta']],
            ['title' => 'Persona que Solicita', 'fields' => ['solicitante']],
            // ['title' => 'Detalle de la Operación', 'subtitle' => 'Agregue una o varias operaciones acumuladas.', 'fields' => ['operaciones_acumuladas', 'operaciones']],
            [
                'title' => 'Detalle de la Operación',
                'subtitle' => 'Agregue una o varias operaciones acumuladas.',
                'fields' => ['operaciones_acumuladas', 'operaciones'],
                'subnav' => [
                    'array' => 'operaciones',
                    'children' => [
                        ['field' => 'adquirentes', 'label' => 'Adquirentes'],
                        ['field' => 'vendedores', 'label' => 'Vendedores'],
                        ['field' => 'inmueble', 'label' => 'Inmueble'],
                        ['field' => 'pagos', 'label' => 'Pagos'],
                    ],
                ]
            ],
        ];

        return ['fields' => $fields, 'sections' => $sections];
    }

    public function postProcess(array $merged): array
    {
        return $merged;
    }
}
