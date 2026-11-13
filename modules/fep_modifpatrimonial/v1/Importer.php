<?php

namespace Modules\FepModifpatrimonial\V1;

use App\Modules\ImportException;

require_once __DIR__ . '/../../fep/v1/Importer.php';

class Importer extends \Modules\Fep\V1\Importer
{
    protected function parseActivity(\DOMElement $ta): array
    {
        $m = $this->first($ta, 'modificacion_patrimonial');
        if (!$m) {
            throw new ImportException('El informe no es de tipo Modificación Patrimonial.');
        }
        $pm = $this->first($m, 'persona_moral_modifica');
        $dm = $this->first($m, 'datos_modificacion');
        $accionistas = [];
        if ($dm) {
            foreach ($this->all($dm, 'datos_accionista') as $da) {
                $tp = $this->first($da, 'tipo_persona');
                $accionistas[] = array_merge($tp ? $this->persona($tp) : [], ['numero_acciones' => $this->text($da, 'numero_acciones')]);
            }
        }
        return [
            'persona_moral_modifica' => [
                'denominacion_razon' => $pm ? $this->text($pm, 'denominacion_razon') : '',
                'fecha_constitucion' => $pm ? $this->text($pm, 'fecha_constitucion') : '',
                'rfc' => $pm ? $this->text($pm, 'rfc') : '',
                'pais_nacionalidad' => $pm ? $this->text($pm, 'pais_nacionalidad') : '',
                'giro_mercantil' => $pm ? $this->text($pm, 'giro_mercantil') : '',
                'numero_total_acciones' => $pm ? $this->text($pm, 'numero_total_acciones') : '',
                'motivo_modificacion' => $pm ? $this->text($pm, 'motivo_modificacion') : '',
                'instrumento_publico' => $pm ? $this->text($pm, 'instrumento_publico') : '',
            ],
            'datos_modificacion' => [
                'tipo_modificacion_capital_fijo' => $dm ? $this->text($dm, 'tipo_modificacion_capital_fijo') : '',
                'inicial_capital_fijo' => $dm ? $this->text($dm, 'inicial_capital_fijo') : '',
                'final_capital_fijo' => $dm ? $this->text($dm, 'final_capital_fijo') : '',
                'tipo_modificacion_capital_variable' => $dm ? $this->text($dm, 'tipo_modificacion_capital_variable') : '',
                'inicial_capital_variable' => $dm ? $this->text($dm, 'inicial_capital_variable') : '',
                'final_capital_variable' => $dm ? $this->text($dm, 'final_capital_variable') : '',
                'accionistas' => $accionistas,
            ],
        ];
    }
}
