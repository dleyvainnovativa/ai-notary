<?php

namespace Modules\FepEscision\V1;

use App\Modules\ImportException;

require_once __DIR__ . '/../../fep/v1/Importer.php';

class Importer extends \Modules\Fep\V1\Importer
{
    protected function parseActivity(\DOMElement $ta): array
    {
        $e = $this->first($ta, 'escision');
        if (!$e) {
            throw new ImportException('El informe no es de tipo Escisión.');
        }
        $esc = $this->first($e, 'datos_escindente');
        $escindente = [];
        if ($esc) {
            $escindente = array_merge($this->moralBlock($esc), [
                'escindente_subsiste' => $this->text($esc, 'escindente_subsiste'),
                'accionistas' => $this->accionistas($esc, 'datos_accionista_escindente'),
            ]);
        }
        $escindidas = [];
        if ($dsds = $this->first($e, 'datos_escindidas')) {
            foreach ($this->all($dsds, 'dato_escindida') as $de) {
                $escindidas[] = array_merge($this->moralBlock($de), [
                    'numero_total_acciones' => $this->text($de, 'numero_total_acciones'),
                    'accionistas' => $this->accionistas($de, 'datos_accionista'),
                ]);
            }
        }
        return [
            'escindente' => $escindente,
            'escindidas_determinadas' => $dsds ? $this->text($dsds, 'escindidas_determinadas') : '',
            'escindidas' => $escindidas,
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

    protected function accionistas(\DOMElement $parent, string $tag): array
    {
        $out = [];
        foreach ($this->all($parent, $tag) as $da) {
            $tp = $this->first($da, 'tipo_persona');
            $out[] = array_merge($tp ? $this->persona($tp) : [], ['numero_acciones' => $this->text($da, 'numero_acciones')]);
        }
        return $out;
    }
}
