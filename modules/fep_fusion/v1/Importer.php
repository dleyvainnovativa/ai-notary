<?php

namespace Modules\FepFusion\V1;

use App\Modules\ImportException;

require_once __DIR__ . '/../../fep/v1/Importer.php';

class Importer extends \Modules\Fep\V1\Importer
{
    protected function parseActivity(\DOMElement $ta): array
    {
        $f = $this->first($ta, 'fusion');
        if (!$f) {
            throw new ImportException('El informe no es de tipo Fusión.');
        }
        $fusionadas = [];
        if ($fds = $this->first($f, 'datos_fusionadas')) {
            foreach ($this->all($fds, 'datos_fusionada') as $fd) {
                $fusionadas[] = $this->moralBlock($fd);
            }
        }
        $dft = $this->first($f, 'datos_fusionante');
        $fusionante = null;
        if ($dft && ($ftEl = $this->first($dft, 'fusionante'))) {
            $fusionante = array_merge($this->moralBlock($ftEl), [
                'numero_total_acciones' => $this->text($ftEl, 'numero_total_acciones'),
                'accionistas' => $this->accionistas($ftEl),
            ]);
        }
        return [
            'tipo_fusion' => $this->text($f, 'tipo_fusion'),
            'fusionadas' => $fusionadas,
            'fusionante_determinadas' => $dft ? $this->text($dft, 'fusionante_determinadas') : '',
            'fusionante' => $fusionante,
        ];
    }

    protected function moralBlock(\DOMElement $n): array
    {
        return [
            'denominacion_razon' => $this->text($n, 'denominacion_razon'),
            'fecha_constitucion' => $this->text($n, 'fecha_constitucion'),
            'rfc' => $this->text($n, 'rfc'),
            'pais_nacionalidad' => $this->text($n, 'pais_nacionalidad'),
            'giro_mercantil' => $this->text($n, 'giro_mercantil'),
            'capital_social_fijo' => $this->text($n, 'capital_social_fijo'),
            'capital_social_variable' => $this->text($n, 'capital_social_variable'),
            'folio_mercantil' => $this->text($n, 'folio_mercantil'),
        ];
    }

    protected function accionistas(\DOMElement $parent): array
    {
        $out = [];
        foreach ($this->all($parent, 'datos_accionista') as $da) {
            $tp = $this->first($da, 'tipo_persona');
            $out[] = array_merge($tp ? $this->persona($tp) : [], ['numero_acciones' => $this->text($da, 'numero_acciones')]);
        }
        return $out;
    }
}
