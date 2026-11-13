<?php

namespace App\Services\Fep;

/**
 * Builds the FEP <archivo> XML envelope and hands each operation's
 * <tipo_actividad> block to the module's activity builder.
 *
 * Every FEP module shares this envelope; only the activity child differs.
 * The writer exposes small DOM helpers (el / text / append) so modules and
 * FepPersonas build nodes without touching DOMDocument directly.
 *
 * Output is well-formed, UTF-8, pretty-printed. It is validated against the
 * module's fep.xsd by validate(); byte-for-byte identity with SAT's example
 * files is NOT a goal (SAT validates against the XSD, not a sample file) —
 * the manual tests assert XSD-validity + structural/value equality instead.
 */
final class FepXmlWriter
{
    public const NS  = 'http://www.uif.shcp.gob.mx/recepcion/fep';
    public const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    private \DOMDocument $doc;

    public function __construct()
    {
        $this->doc = new \DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;
        $this->doc->preserveWhiteSpace = false;
    }

    /** Create an element in the FEP default namespace, optionally with text. */
    public function el(string $name, ?string $text = null): \DOMElement
    {
        $node = $this->doc->createElementNS(self::NS, $name);
        if ($text !== null && $text !== '') {
            $node->appendChild($this->doc->createTextNode($text));
        }
        return $node;
    }

    /** Append a child <name>text</name> to $parent ONLY when $text is non-empty. */
    public function opt(\DOMElement $parent, string $name, ?string $text): void
    {
        if ($text !== null && $text !== '') {
            $parent->appendChild($this->el($name, $text));
        }
    }

    /** Append a child <name>text</name> always (required element; empty allowed by caller). */
    public function req(\DOMElement $parent, string $name, ?string $text): void
    {
        $parent->appendChild($this->el($name, (string) $text));
    }

    /**
     * @param array    $data             canonical FEP review data (see FepReview shape)
     * @param callable $activityBuilder  fn(self $w, \DOMElement $tipoActividad, array $actividad): void
     */
    public function build(array $data, callable $activityBuilder): string
    {
        $root = $this->el('archivo');
        $root->setAttributeNS(self::XSI, 'xsi:schemaLocation', self::NS . ' fep.xsd');
        $this->doc->appendChild($root);

        foreach ($data['informes'] ?? [$data['informe'] ?? $data] as $informe) {
            $root->appendChild($this->buildInforme($informe, $activityBuilder));
        }

        return $this->doc->saveXML();
    }

    private function buildInforme(array $informe, callable $activityBuilder): \DOMElement
    {
        $node = $this->el('informe');
        $this->req($node, 'mes_reportado', FepValueFormatter::mesReportado($informe['mes_reportado'] ?? ''));

        $so = $informe['sujeto_obligado'] ?? [];
        $soEl = $this->el('sujeto_obligado');
        $this->opt($soEl, 'clave_entidad_colegiada', strtoupper(trim((string) ($so['clave_entidad_colegiada'] ?? ''))));
        $this->req($soEl, 'clave_sujeto_obligado', strtoupper(trim((string) ($so['clave_sujeto_obligado'] ?? ''))));
        $this->req($soEl, 'clave_actividad', 'FEP');
        $this->opt($soEl, 'exento', trim((string) ($so['exento'] ?? '')));
        $node->appendChild($soEl);

        foreach ($informe['avisos'] ?? [] as $aviso) {
            $node->appendChild($this->buildAviso($aviso, $activityBuilder));
        }
        return $node;
    }

    private function buildAviso(array $aviso, callable $activityBuilder): \DOMElement
    {
        $node = $this->el('aviso');
        $this->req($node, 'referencia_aviso', strtoupper(trim((string) ($aviso['referencia_aviso'] ?? ''))));

        $mod = $aviso['modificatorio'] ?? null;
        if (is_array($mod) && trim((string) ($mod['folio_modificacion'] ?? '')) !== '') {
            $modEl = $this->el('modificatorio');
            $this->req($modEl, 'folio_modificacion', trim((string) $mod['folio_modificacion']));
            $this->req($modEl, 'descripcion_modificacion', FepValueFormatter::denominacion($mod['descripcion_modificacion'] ?? ''));
            $node->appendChild($modEl);
        }

        $this->req($node, 'prioridad', FepValueFormatter::digits($aviso['prioridad'] ?? '1', 1) ?: '1');

        $al = $aviso['alerta'] ?? [];
        $alEl = $this->el('alerta');
        $this->req($alEl, 'tipo_alerta', FepValueFormatter::digits($al['tipo_alerta'] ?? '', 4));
        $this->opt($alEl, 'descripcion_alerta', FepValueFormatter::denominacion($al['descripcion_alerta'] ?? ''));
        $node->appendChild($alEl);

        foreach ($aviso['personas_aviso'] ?? [] as $pa) {
            $node->appendChild($this->personaAviso($pa));
        }

        $det = $this->el('detalle_operaciones');
        foreach ($aviso['operaciones'] ?? [] as $op) {
            $opEl = $this->el('datos_operacion');
            $this->req($opEl, 'instrumento_publico', strtoupper(trim((string) ($op['instrumento_publico'] ?? ''))));
            $this->req($opEl, 'fecha_operacion', FepValueFormatter::fecha($op['fecha_operacion'] ?? ''));
            $ta = $this->el('tipo_actividad');
            $activityBuilder($this, $ta, $op['actividad'] ?? []);
            $opEl->appendChild($ta);
            $det->appendChild($opEl);
        }
        $node->appendChild($det);
        return $node;
    }

    /** <persona_aviso>: the person requesting the formalization (no país/actividad). */
    private function personaAviso(array $p): \DOMElement
    {
        $node = $this->el('persona_aviso');
        $this->req($node, 'nombre', FepValueFormatter::nombre($p['nombre'] ?? ''));
        $this->req($node, 'apellido_paterno', FepValueFormatter::apellido($p['apellido_paterno'] ?? '', $p['apellido_materno'] ?? ''));
        $this->req($node, 'apellido_materno', FepValueFormatter::apellido($p['apellido_materno'] ?? '', $p['apellido_paterno'] ?? ''));
        $this->opt($node, 'fecha_nacimiento', FepValueFormatter::fecha($p['fecha_nacimiento'] ?? ''));
        $this->opt($node, 'rfc', FepValueFormatter::rfcFisica($p['rfc'] ?? ''));
        $this->opt($node, 'curp', FepValueFormatter::curp($p['curp'] ?? ''));
        return $node;
    }

    /** Validate an XML string against an XSD file. Returns [] on success, else error strings. */
    public static function validate(string $xml, string $xsdPath): array
    {
        if (!is_file($xsdPath)) return ["XSD not found: {$xsdPath}"];
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $doc = new \DOMDocument();
        if (!$doc->loadXML($xml)) {
            $errs = self::collect();
            libxml_use_internal_errors($prev);
            return $errs ?: ['Malformed XML'];
        }
        $ok = $doc->schemaValidate($xsdPath);
        $errs = $ok ? [] : self::collect();
        libxml_use_internal_errors($prev);
        return $errs;
    }

    private static function collect(): array
    {
        $out = [];
        foreach (libxml_get_errors() as $e) {
            $out[] = trim($e->message) . ' (line ' . $e->line . ')';
        }
        libxml_clear_errors();
        return $out;
    }
}
