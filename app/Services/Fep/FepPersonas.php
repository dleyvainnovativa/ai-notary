<?php

namespace App\Services\Fep;

/**
 * The three FEP person types and their variants, reused across every activity
 * block. Builds the XML nodes from canonical persona data and parses them back
 * for round-trip import.
 *
 * Canonical persona array:
 *   tipo: 'fisica' | 'moral' | 'fideicomiso'
 *   fisica:      nombre, apellido_paterno, apellido_materno, fecha_nacimiento?, rfc?, curp?, pais_nacionalidad, actividad_economica
 *   moral:       denominacion_razon, fecha_constitucion?, rfc?, pais_nacionalidad, giro_mercantil
 *   fideicomiso: denominacion_razon, rfc?, identificador_fideicomiso?
 *
 * Variants (per XSD):
 *   'full'    → <tipo_persona>         persona_fisica (w/ actividad) | persona_moral (w/ giro) | fideicomiso
 *   'simple'  → <tipo_persona_simple>  persona_fisica_simple (no actividad) | persona_moral_simple (no giro) | fideicomiso
 *   'garante' → <tipo_persona_garante> persona_fisica_garante (no país/actividad) | persona_moral_garante (no país/giro) | fideicomiso
 */
final class FepPersonas
{
    private const F = FepValueFormatter::class;

    /**
     * The wrapper element is ALWAYS <tipo_persona> — the schema distinguishes
     * full / simple / garante / fideicomisario by the element's XSD *type*, not
     * by the tag name. $variant only controls which persona sub-fields are emitted.
     */
    public static function tipoPersona(FepXmlWriter $w, array $p, string $variant = 'full'): \DOMElement
    {
        $node = $w->el('tipo_persona');
        $node->appendChild(self::personaChild($w, $p, $variant));
        return $node;
    }

    /**
     * Fideicomisario persona (XSD tipo_persona_1_type) — the element is named
     * <tipo_persona> but carries a leading <tipo_movimiento_fideicomisario>
     * before the full persona choice. $p['tipo_movimiento_fideicomisario'] = digit.
     */
    public static function tipoPersona1(FepXmlWriter $w, array $p): \DOMElement
    {
        $node = $w->el('tipo_persona');
        $w->req($node, 'tipo_movimiento_fideicomisario', FepValueFormatter::digits($p['tipo_movimiento_fideicomisario'] ?? '1', 1) ?: '1');
        $node->appendChild(self::personaChild($w, $p, 'full'));
        return $node;
    }

    /** The inner persona element for a given variant, chosen by $p['tipo']. */
    public static function personaChild(FepXmlWriter $w, array $p, string $variant = 'full'): \DOMElement
    {
        // tipo may arrive as a form-select digit (1/2/3) or a canonical name
        // (from XLSM import / round-trip). Normalise to the name before dispatch.
        $tipo = strtolower((string) ($p['tipo'] ?? 'fisica'));
        $tipo = ['1' => 'fisica', '2' => 'moral', '3' => 'fideicomiso'][$tipo] ?? $tipo;
        return match ($tipo) {
            'moral'       => self::moral($w, $p, $variant),
            'fideicomiso' => self::fideicomiso($w, $p),
            default       => self::fisica($w, $p, $variant),
        };
    }

    public static function fisica(FepXmlWriter $w, array $p, string $variant = 'full'): \DOMElement
    {
        $f = self::F;
        $node = $w->el('persona_fisica');
        $w->req($node, 'nombre', $f::nombre($p['nombre'] ?? ''));
        $w->req($node, 'apellido_paterno', $f::apellido($p['apellido_paterno'] ?? '', $p['apellido_materno'] ?? ''));
        $w->req($node, 'apellido_materno', $f::apellido($p['apellido_materno'] ?? '', $p['apellido_paterno'] ?? ''));
        $w->opt($node, 'fecha_nacimiento', $f::fecha($p['fecha_nacimiento'] ?? ''));
        $w->opt($node, 'rfc', $f::rfcFisica($p['rfc'] ?? ''));
        $w->opt($node, 'curp', $f::curp($p['curp'] ?? ''));
        if ($variant !== 'garante') {
            $w->req($node, 'pais_nacionalidad', $f::pais($p['pais_nacionalidad'] ?? ''));
        }
        if ($variant === 'full') {
            $w->req($node, 'actividad_economica', $f::digito7($p['actividad_economica'] ?? ''));
        }
        return $node;
    }

    public static function moral(FepXmlWriter $w, array $p, string $variant = 'full'): \DOMElement
    {
        $f = self::F;
        $node = $w->el('persona_moral');
        $w->req($node, 'denominacion_razon', $f::denominacion($p['denominacion_razon'] ?? ''));
        $w->opt($node, 'fecha_constitucion', $f::fecha($p['fecha_constitucion'] ?? ''));
        $w->opt($node, 'rfc', $f::rfcMoral($p['rfc'] ?? ''));
        if ($variant !== 'garante') {
            $w->req($node, 'pais_nacionalidad', $f::pais($p['pais_nacionalidad'] ?? ''));
        }
        if ($variant === 'full') {
            $w->req($node, 'giro_mercantil', $f::digito7($p['giro_mercantil'] ?? ''));
        }
        return $node;
    }

    public static function fideicomiso(FepXmlWriter $w, array $p): \DOMElement
    {
        $f = self::F;
        $node = $w->el('fideicomiso');
        $w->req($node, 'denominacion_razon', $f::denominacion($p['denominacion_razon'] ?? ''));
        $w->opt($node, 'rfc', $f::rfcMoral($p['rfc'] ?? ''));
        $w->opt($node, 'identificador_fideicomiso', $f::denominacion($p['identificador_fideicomiso'] ?? ''));
        return $node;
    }

    /**
     * formSchema fragment for a persona with a tipo selector. $cases controls
     * which person types are offered. actividad/giro/pais included per variant.
     * Shared so every activity form renders people identically.
     */
    public static function formFragment(array $opts = []): array
    {
        $variant = $opts['variant'] ?? 'full';       // full | simple | garante
        $cases   = $opts['cases'] ?? ['fisica', 'moral', 'fideicomiso'];

        // "Tipo de Persona" is a fixed 3-value discriminator (física/moral/fideicomiso),
        // not a data catalog like país. Default its options here (values 1/2/3, matching
        // the shared 'persona_tipo_select' classifier and UIF's catalogo_persona) so no
        // per-module catalogo_persona.json is needed. A module may still override via
        // persona_catalog. Values are normalised back to names at export (personaChild).
        $catPersona = $opts['persona_catalog'] ?? [];
        if (!$catPersona) {
            $tipoMeta = [
                'fisica'      => ['value' => '1', 'label' => 'Persona Física'],
                'moral'       => ['value' => '2', 'label' => 'Persona Moral'],
                'fideicomiso' => ['value' => '3', 'label' => 'Fideicomiso'],
            ];
            $catPersona = array_values(array_map(
                fn ($c) => $tipoMeta[$c] ?? ['value' => $c, 'label' => ucfirst($c)],
                $cases
            ));
        }
        $catPais    = $opts['pais_catalog'] ?? [];
        $catAct     = $opts['actividad_catalog'] ?? [];
        $catGiro    = $opts['giro_catalog'] ?? [];

        $fields = [
            'tipo' => ['label' => 'Tipo de Persona', 'type' => 'select', 'required' => true, 'options' => $catPersona],
            // física
            'nombre' => ['label' => 'Nombre(s)', 'type' => 'text', 'required_in_cases' => ['fisica']],
            'apellido_paterno' => ['label' => 'Apellido Paterno', 'type' => 'text', 'required_in_cases' => ['fisica']],
            'apellido_materno' => ['label' => 'Apellido Materno', 'type' => 'text', 'show_in_cases' => ['fisica']],
            'fecha_nacimiento' => ['label' => 'Fecha de Nacimiento', 'type' => 'date', 'show_in_cases' => ['fisica'], 'derive' => ['rule' => 'birthdate_from_id', 'curp' => 'curp', 'rfc' => 'rfc']],
            'curp' => ['label' => 'CURP', 'type' => 'text', 'validation' => ['format' => 'curp'], 'show_in_cases' => ['fisica'], 'optional_when' => ['rfc' => ['op' => 'filled']]],
            // moral / fideicomiso
            'denominacion_razon' => ['label' => 'Denominación o Razón Social', 'type' => 'text', 'col' => 'full', 'required_in_cases' => ['moral', 'fideicomiso']],
            'fecha_constitucion' => ['label' => 'Fecha de Constitución', 'type' => 'date', 'show_in_cases' => ['moral'], 'derive' => ['rule' => 'date_from_rfc_moral', 'rfc' => 'rfc']],
            'identificador_fideicomiso' => ['label' => 'Identificador del Fideicomiso', 'type' => 'text', 'col' => 'full', 'show_in_cases' => ['fideicomiso']],
            // shared id
            'rfc' => ['label' => 'RFC', 'type' => 'text', 'validation' => ['format' => 'rfc'], 'optional_when' => ['curp' => ['op' => 'filled'], 'fecha_nacimiento' => ['op' => 'filled']]],
        ];

        if ($variant !== 'garante') {
            $fields['pais_nacionalidad'] = ['label' => 'País de Nacionalidad', 'type' => 'select', 'options' => $catPais, 'required_in_cases' => ['fisica', 'moral']];
        }
        if ($variant === 'full') {
            $fields['actividad_economica'] = ['label' => 'Actividad Económica u Ocupación', 'type' => 'select', 'col' => 'full', 'options' => $catAct, 'required_in_cases' => ['fisica']];
            $fields['giro_mercantil'] = ['label' => 'Giro Mercantil u Objeto Social', 'type' => 'select', 'col' => 'full', 'options' => $catGiro, 'required_in_cases' => ['moral']];
        }

        return [
            'cases' => $cases,
            'classifier' => ['rule' => 'persona_tipo_select', 'tipo_field' => 'tipo'],
            'itemSchema' => $fields,
        ];
    }

    /** Parse a <tipo_persona*>/<persona_*> DOM node back to canonical persona data. */
    public static function parse(\DOMElement $wrapper): array
    {
        // $wrapper is <tipo_persona[_simple|_garante]> or a direct persona element.
        $child = null;
        foreach ($wrapper->childNodes as $n) {
            if ($n instanceof \DOMElement && in_array($n->localName, ['persona_fisica', 'persona_moral', 'fideicomiso'], true)) {
                $child = $n; break;
            }
        }
        if (!$child) return [];
        $get = fn(string $t) => self::childText($child, $t);
        return match ($child->localName) {
            'persona_moral' => array_filter([
                'tipo' => 'moral',
                'denominacion_razon' => $get('denominacion_razon'),
                'fecha_constitucion' => $get('fecha_constitucion'),
                'rfc' => $get('rfc'),
                'pais_nacionalidad' => $get('pais_nacionalidad'),
                'giro_mercantil' => $get('giro_mercantil'),
            ], fn($v) => $v !== ''),
            'fideicomiso' => array_filter([
                'tipo' => 'fideicomiso',
                'denominacion_razon' => $get('denominacion_razon'),
                'rfc' => $get('rfc'),
                'identificador_fideicomiso' => $get('identificador_fideicomiso'),
            ], fn($v) => $v !== ''),
            default => array_filter([
                'tipo' => 'fisica',
                'nombre' => $get('nombre'),
                'apellido_paterno' => $get('apellido_paterno'),
                'apellido_materno' => $get('apellido_materno'),
                'fecha_nacimiento' => $get('fecha_nacimiento'),
                'rfc' => $get('rfc'),
                'curp' => $get('curp'),
                'pais_nacionalidad' => $get('pais_nacionalidad'),
                'actividad_economica' => $get('actividad_economica'),
            ], fn($v) => $v !== ''),
        };
    }

    private static function childText(\DOMElement $el, string $local): string
    {
        foreach ($el->childNodes as $n) {
            if ($n instanceof \DOMElement && $n->localName === $local) return trim($n->textContent);
        }
        return '';
    }
}
