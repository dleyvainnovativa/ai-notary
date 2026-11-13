<?php

namespace Modules\Fep\V1;

use App\Modules\ImporterContract;
use App\Modules\ImportResult;
use App\Modules\ImportException;
use App\Services\Fep\FepPersonas;

/**
 * Base importer: parse a previously generated FEP XML (<archivo>) back into the
 * flat review data, for round-trip fixes at no token cost. The envelope is shared;
 * each concrete module parses its own <tipo_actividad> child via parseActivity().
 *
 * (The official .xlsm template is handled separately by FepXlsmReader, not here —
 * this importer is the XML round-trip, mirroring the UIF/DeclaraNOT TXT importers.)
 */
abstract class Importer implements ImporterContract
{
    /** Parse the module's own <tipo_actividad> child → actividad array. */
    abstract protected function parseActivity(\DOMElement $tipoActividad): array;

    public function parse(string $text): ImportResult
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $ok = $doc->loadXML($text);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            throw new ImportException('El archivo no es un XML válido.');
        }

        $archivo = $doc->documentElement;
        if (!$archivo || $archivo->localName !== 'archivo') {
            throw new ImportException('No es un informe FEP (<archivo>).');
        }

        $informe = $this->first($archivo, 'informe');
        $aviso = $informe ? $this->first($informe, 'aviso') : null;
        if (!$informe || !$aviso) {
            throw new ImportException('El informe FEP no contiene <informe>/<aviso>.');
        }

        $so = $this->first($informe, 'sujeto_obligado');
        $alerta = $this->first($aviso, 'alerta');
        $mod = $this->first($aviso, 'modificatorio');

        $operacion = $this->first($this->first($aviso, 'detalle_operaciones'), 'datos_operacion');
        $tipoActividad = $operacion ? $this->first($operacion, 'tipo_actividad') : null;
        if (!$tipoActividad) {
            throw new ImportException('La operación no contiene <tipo_actividad>.');
        }

        $data = [
            'mes_reportado' => $this->text($informe, 'mes_reportado'),
            'sujeto_obligado' => [
                'clave_sujeto_obligado' => $so ? $this->text($so, 'clave_sujeto_obligado') : '',
                'clave_entidad_colegiada' => $so ? $this->text($so, 'clave_entidad_colegiada') : '',
                'exento' => $so ? $this->text($so, 'exento') : '',
            ],
            'referencia_aviso' => $this->text($aviso, 'referencia_aviso'),
            'prioridad' => $this->text($aviso, 'prioridad'),
            'modificatorio' => $mod ? [
                'folio_modificacion' => $this->text($mod, 'folio_modificacion'),
                'descripcion_modificacion' => $this->text($mod, 'descripcion_modificacion'),
            ] : null,
            'alerta' => [
                'tipo_alerta' => $alerta ? $this->text($alerta, 'tipo_alerta') : '',
                'descripcion_alerta' => $alerta ? $this->text($alerta, 'descripcion_alerta') : '',
            ],
            'personas_aviso' => array_map(fn($pa) => [
                'nombre' => $this->text($pa, 'nombre'),
                'apellido_paterno' => $this->text($pa, 'apellido_paterno'),
                'apellido_materno' => $this->text($pa, 'apellido_materno'),
                'fecha_nacimiento' => $this->text($pa, 'fecha_nacimiento'),
                'rfc' => $this->text($pa, 'rfc'),
                'curp' => $this->text($pa, 'curp'),
            ], $this->all($aviso, 'persona_aviso')),
            'operaciones' => [[
                'instrumento_publico' => $this->text($operacion, 'instrumento_publico'),
                'fecha_operacion' => $this->text($operacion, 'fecha_operacion'),
                'actividad' => $this->parseActivity($tipoActividad),
            ]],
        ];

        return new ImportResult($data, []);
    }

    /* ---- small DOM helpers (shared by subclasses) ---- */

    protected function first(?\DOMElement $el, string $local): ?\DOMElement
    {
        if (!$el) return null;
        foreach ($el->childNodes as $n) {
            if ($n instanceof \DOMElement && $n->localName === $local) return $n;
        }
        return null;
    }

    /** @return \DOMElement[] */
    protected function all(\DOMElement $el, string $local): array
    {
        $out = [];
        foreach ($el->childNodes as $n) {
            if ($n instanceof \DOMElement && $n->localName === $local) $out[] = $n;
        }
        return $out;
    }

    protected function text(?\DOMElement $el, string $local): string
    {
        $c = $this->first($el, $local);
        return $c ? trim($c->textContent) : '';
    }

    /** Parse any <tipo_persona*> node to canonical persona data. */
    protected function persona(\DOMElement $tipoPersona): array
    {
        return FepPersonas::parse($tipoPersona);
    }
}
