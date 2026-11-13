<?php

namespace Modules\Fep\V1;

use App\Modules\ExporterContract;
use App\Services\Fep\FepXmlWriter;

/**
 * Abstract base for every FEP exporter. Builds the shared <archivo> envelope
 * from the flat review data and delegates the per-operation <tipo_actividad>
 * child to the concrete module via buildActivity().
 *
 * Instantiated with `new $class()` (no args) by DocumentController, so paths
 * are resolved from __DIR__ of this base file (modules/fep/v1), where fep.xsd lives.
 */
abstract class Exporter implements ExporterContract
{
    public function supportedFormats(): array
    {
        return ['xml'];
    }

    /** Concrete module appends its activity element (e.g. <avaluo>…) to $tipoActividad. */
    abstract protected function buildActivity(FepXmlWriter $w, \DOMElement $tipoActividad, array $actividad): void;

    public function export(array $data, string $format): string
    {
        if ($format !== 'xml') {
            throw new \InvalidArgumentException("FEP only exports xml, got {$format}");
        }
        $writer = new FepXmlWriter();
        $structured = ['informes' => [$this->buildInforme($data)]];
        return $writer->build($structured, fn($w, $ta, $a) => $this->buildActivity($w, $ta, $a));
    }

    /** Build + validate against fep.xsd. Returns [xml, errors[]]. */
    public function exportChecked(array $data): array
    {
        $xml = $this->export($data, 'xml');
        return [$xml, FepXmlWriter::validate($xml, __DIR__ . '/fep.xsd')];
    }

    /** Map the flat review data → one <informe> with a single <aviso>. */
    protected function buildInforme(array $data): array
    {
        $so  = $data['sujeto_obligado'] ?? [];
        $not = $data['_notaria'] ?? [];

        return [
            'mes_reportado' => $data['mes_reportado'] ?? '',
            'sujeto_obligado' => [
                'clave_entidad_colegiada' => $so['clave_entidad_colegiada'] ?? '',
                'clave_sujeto_obligado' => $so['clave_sujeto_obligado'] ?? ($not['rfc'] ?? ''),
                'exento' => $so['exento'] ?? '',
            ],
            'avisos' => [[
                'referencia_aviso' => $data['referencia_aviso'] ?? '',
                'modificatorio' => $data['modificatorio'] ?? null,
                'prioridad' => $data['prioridad'] ?? '1',
                'alerta' => $data['alerta'] ?? [],
                'personas_aviso' => $data['personas_aviso'] ?? [],
                'operaciones' => $this->normalizeOperaciones($data),
            ]],
        ];
    }

    /**
     * One escritura = one aviso. If the review data carries an 'operaciones'
     * array, use it; otherwise treat the whole payload as a single operation
     * whose 'actividad' is the module-specific block under $data['actividad'].
     */
    protected function normalizeOperaciones(array $data): array
    {
        if (!empty($data['operaciones']) && is_array($data['operaciones'])) {
            return $data['operaciones'];
        }
        return [[
            'instrumento_publico' => $data['instrumento_publico'] ?? '',
            'fecha_operacion' => $data['fecha_operacion'] ?? '',
            'actividad' => $data['actividad'] ?? [],
        ]];
    }
}
