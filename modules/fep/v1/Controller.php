<?php

namespace Modules\Fep\V1;

use App\Modules\ModuleControllerContract;
use App\Modules\ModuleInput;
use App\Services\Fep\FepPersonas;

/**
 * Abstract base controller for the FEP modules. Builds the shared <aviso>
 * envelope form (datos del informe, sujeto obligado, persona que solicita,
 * detalle de la operación) and leaves the per-type activity block to the
 * concrete module via activityFields() / activitySubnav().
 *
 * Concrete modules implement inputs() (their own prompt/schema/output files)
 * and activityFields(); everything else is shared.
 */
abstract class Controller implements ModuleControllerContract
{
    public function __construct(protected string $dir) {}

    /** Load a catalog from this module's /catalogs, falling back to the parent (extends). */
    protected function cat(string $name): array
    {
        return app(\App\Services\CatalogService::class)->load($name, $this->dir) ?? [];
    }

    /** The per-type activity fields, merged into each <datos_operacion>. */
    abstract protected function activityFields(): array;

    /** Optional subnav children for the "Detalle de la Operación" section. */
    protected function activitySubnav(): array
    {
        return [];
    }

    /** Reusable persona fragment with this module's catalogs preloaded. */
    protected function personaFragment(array $opts = []): array
    {
        return FepPersonas::formFragment(array_merge([
            'persona_catalog'   => $this->cat('catalogo_persona'),
            'pais_catalog'      => $this->cat('catalogo_pais'),
            'actividad_catalog' => $this->cat('catalogo_actividad_economica'),
            'giro_catalog'      => $this->cat('catalogo_giro_mercantil'),
        ], $opts));
    }

    public function schema(): array
    {
        $inputs = $this->inputs();
        $input = $inputs[0] ?? null;
        return $input ? $input->schema($this->dir) : [];
    }

    public function postProcess(array $merged): array
    {
        return $merged;
    }

    public function formSchema(): array
    {
        $catPrioridad = $this->cat('catalogo_prioridad');

        $operacionItem = array_merge([
            'instrumento_publico' => ['label' => 'Número de Instrumento Público', 'type' => 'text', 'required' => true],
            'fecha_operacion' => ['label' => 'Fecha de la operación', 'type' => 'date', 'required' => true],
        ], $this->activityFields());

        $fields = [
            /* ===== Datos del Informe ===== */
            'mes_reportado' => ['label' => 'Periodo reportado (AAAAMM)', 'type' => 'text', 'required' => true, 'subtitle' => 'Mes de la operación, p. ej. 202411.'],
            'sujeto_obligado' => [
                'label' => 'Datos del fedatario (sujeto obligado)',
                'type' => 'object',
                'itemSchema' => [
                    'clave_sujeto_obligado' => ['label' => 'RFC del fedatario', 'type' => 'text', 'required' => true, 'validation' => ['format' => 'rfc']],
                    'clave_entidad_colegiada' => ['label' => 'RFC de la entidad colegiada', 'type' => 'text', 'validation' => ['format' => 'rfc'], 'subtitle' => 'Solo si se reporta a través de una entidad colegiada.'],
                    'exento' => ['label' => '¿Informe exento (art. 27 Bis)?', 'type' => 'select', 'options' => [
                        ['label' => 'No', 'value' => ''], ['label' => 'Sí', 'value' => '1'],
                    ]],
                ],
            ],

            /* ===== Datos del Aviso ===== */
            'referencia_aviso' => ['label' => 'Referencia del Aviso', 'type' => 'text', 'required' => true],
            'prioridad' => ['label' => 'Prioridad', 'type' => 'select', 'required' => true, 'options' => $catPrioridad],
            'modificatorio' => [
                'label' => '¿Aviso modificatorio?',
                'type' => 'object',
                'itemSchema' => [
                    'folio_modificacion' => ['label' => 'Folio del aviso que se modifica', 'type' => 'text', 'subtitle' => 'Patrón AAAA-999999999. Déjalo vacío si no es modificatorio.'],
                    'descripcion_modificacion' => ['label' => 'Descripción de la modificación', 'type' => 'text', 'col' => 'full', 'required_if' => ['folio_modificacion' => ['op' => 'filled']]],
                ],
            ],
            'alerta' => [
                'label' => 'Alerta',
                'type' => 'object',
                'itemSchema' => [
                    'tipo_alerta' => ['label' => 'Tipo de Alerta', 'type' => 'text', 'required' => true, 'validation' => ['pattern' => '^\\d{3,4}$'], 'subtitle' => 'Clave de 3-4 dígitos del catálogo de alertas de la UIF.'],
                    'descripcion_alerta' => ['label' => 'Descripción de la alerta', 'type' => 'text', 'col' => 'full', 'required_if' => ['tipo_alerta' => '9999']],
                ],
            ],
            'personas_aviso' => [
                'item_label' => 'Persona',
                'label' => 'Persona(s) que solicita(n) la formalización',
                'type' => 'array',
                'col' => 'full',
                'itemSchema' => [
                    'nombre' => ['label' => 'Nombre(s)', 'type' => 'text', 'required' => true],
                    'apellido_paterno' => ['label' => 'Apellido Paterno', 'type' => 'text', 'required' => true],
                    'apellido_materno' => ['label' => 'Apellido Materno', 'type' => 'text', 'required' => true],
                    'fecha_nacimiento' => ['label' => 'Fecha de Nacimiento', 'type' => 'date', 'derive' => ['rule' => 'birthdate_from_id', 'curp' => 'curp', 'rfc' => 'rfc']],
                    'rfc' => ['label' => 'RFC', 'type' => 'text', 'validation' => ['format' => 'rfc']],
                    'curp' => ['label' => 'CURP', 'type' => 'text', 'validation' => ['format' => 'curp']],
                ],
            ],

            /* ===== Detalle de la Operación ===== */
            'operaciones' => [
                'item_label' => 'Operación',
                'label' => 'Detalle de la Operación',
                'type' => 'array',
                'min' => 1,   // FEP always has at least one operation → seed a row so its fields show
                'itemSchema' => $operacionItem,
            ],
        ];

        $operacionesSection = [
            'title' => 'Detalle de la Operación',
            'fields' => ['operaciones'],
        ];
        if ($subnav = $this->activitySubnav()) {
            $operacionesSection['subnav'] = ['array' => 'operaciones', 'children' => $subnav];
        }

        $sections = [
            ['title' => 'Datos del Informe', 'fields' => ['mes_reportado', 'sujeto_obligado']],
            ['title' => 'Datos del Aviso', 'fields' => ['referencia_aviso', 'prioridad', 'modificatorio', 'alerta']],
            ['title' => 'Persona que Solicita', 'fields' => ['personas_aviso']],
            $operacionesSection,
        ];

        return ['fields' => $fields, 'sections' => $sections];
    }
}
