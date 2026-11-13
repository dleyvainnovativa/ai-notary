<?php

namespace Modules\FepConstitucionfid\V1;

/**
 * Constitución/modificación de fideicomiso xlsm → review data.
 * Cells per FedatarioConstitucionFid_v4_8 Sheet1.cls (the most intricate template):
 *   row 27: B tipo_movimiento(,) · C tipo_fideicomiso(,) · D descripcion · E rfc ·
 *     F identificador_fideicomiso · G denominacion_razon · H monto_patrimonio ·
 *   fideicomitentes i=0..14: física B34+i (tipo_mov J, standard persona full),
 *     moral B52+i (tipo_mov G), fideicomiso H52+i (tipo_mov K, denom via col H) ·
 *   patrimonios k=0..14 at 73+k, matched to a fideicomitente by col L == "R0n - NAME":
 *     monetario B (moneda, C monto), inmueble D (tipo_inmueble, E cp, H folio, I importe),
 *     otro J (descripcion, K valor) ·
 *   fideicomisarios r=0..9: determinados flag in col B, persona column-SHIFTED (nombre at C,
 *     not B): física 94+r (tipo_mov K), moral 107+r (tipo_mov H), fideicomiso 120+r (tipo_mov F) ·
 *   comité técnico row 133: C comite_tecnico(,) · E modificacion_comite_tecnico(,).
 *
 * NOTE: the patrimonio→fideicomitente association is by a computed "R0n - NAME" key
 * (SAT's prefijoRenglon). Faithfully transcribed; verify against a real filled .xlsm,
 * especially with multiple fideicomitentes.
 */
class XlsmReader extends \App\Services\Fep\FepXlsmReader
{
    public const TEMPLATE = 'FedatarioConstitucionFid';
    public const VERSION = '4_8';

    private const N_FTES = 15;   // NUMERO_FIDEICOMITENTES
    private const N_PATR = 15;   // NUMERO_PATRIMONIOS
    private const N_SARIOS = 10; // NUMERO_FIDEICOMISARIOS

    protected function readActivity(): array
    {
        $fideicomitentes = [];
        for ($i = 0; $i < self::N_FTES; $i++) {
            [$fis, $mor, $fid] = [34 + $i, 52 + $i, 52 + $i];
            if ($this->g("B{$fis}") !== '') {
                $p = array_merge(['tipo_movimiento_fideicomitente' => $this->clave1($this->g("J{$fis}"))], $this->personaFisica($fis, true));
                $key = $this->rowKey($i + 1, [$this->g("B{$fis}"), $this->g("C{$fis}"), $this->g("D{$fis}")]);
            } elseif ($this->g("B{$mor}") !== '') {
                $p = array_merge(['tipo_movimiento_fideicomitente' => $this->clave1($this->g("G{$mor}"))], $this->personaMoral($mor, true));
                $key = $this->rowKey($i + 1, [$this->g("B{$mor}")]);
            } elseif ($this->g("H{$fid}") !== '') {
                $p = array_merge(['tipo_movimiento_fideicomitente' => $this->clave1($this->g("K{$fid}"))], $this->fideicomiso($fid));
                $key = $this->rowKey($i + 1, [$this->g("H{$fid}")]);
            } else {
                continue;
            }
            $p['patrimonios'] = $this->patrimoniosFor($key);
            $fideicomitentes[] = $p;
        }

        // Fideicomisarios: col B holds the "determinados" flag (SI/NO); the persona
        // (when present) is column-shifted, nombre/denom at col C. XSD allows a persona
        // regardless of the flag, so we read it whenever col C is filled.
        $fideicomisarios = [];
        for ($r = 0; $r < self::N_SARIOS; $r++) {
            [$fis, $mor, $fid] = [94 + $r, 107 + $r, 120 + $r];
            if ($this->g("C{$fis}") !== '') {
                $persona = array_merge(['tipo_movimiento_fideicomisario' => $this->clave1($this->g("K{$fis}"))], $this->personaFisicaFid($fis));
                $det = $this->clave2($this->g("B{$fis}")) ?: 'NO';
            } elseif ($this->g("C{$mor}") !== '') {
                $persona = array_merge(['tipo_movimiento_fideicomisario' => $this->clave1($this->g("H{$mor}"))], $this->personaMoralFid($mor));
                $det = $this->clave2($this->g("B{$mor}")) ?: 'NO';
            } elseif ($this->g("C{$fid}") !== '') {
                $persona = array_merge(['tipo_movimiento_fideicomisario' => $this->clave1($this->g("F{$fid}"))], $this->fideicomisoFid($fid));
                $det = $this->clave2($this->g("B{$fid}")) ?: 'NO';
            } else {
                continue;
            }
            $fideicomisarios[] = ['determinados' => $det, 'personas' => [$persona]];
        }

        return [
            'tipo_movimiento' => $this->clave1($this->g('B27')),
            'tipo_fideicomiso' => $this->clave1($this->g('C27')),
            'descripcion' => $this->g('D27'),
            'rfc' => $this->g('E27'),
            'identificador_fideicomiso' => $this->g('F27'),
            'denominacion_razon' => $this->g('G27'),
            'monto_patrimonio' => $this->g('H27'),
            'fideicomitentes' => $fideicomitentes,
            'fideicomisarios' => $fideicomisarios,
            'comite_tecnico' => [
                'comite_tecnico' => $this->clave2($this->g('C133')),
                'modificacion_comite_tecnico' => $this->clave2($this->g('E133')),
            ],
        ];
    }

    /** SAT prefijoRenglon(n) + uppercased, space-joined name parts. */
    private function rowKey(int $n, array $parts): string
    {
        $name = trim(preg_replace('/\s+/', ' ', implode(' ', array_map('trim', $parts))));
        $prefix = ($n < 10 ? "R0{$n}" : "R{$n}") . ' - ';
        return mb_strtoupper($prefix . $name, 'UTF-8');
    }

    /** Patrimonios whose col L matches this fideicomitente's key. */
    private function patrimoniosFor(string $key): array
    {
        $out = [];
        for ($k = 0; $k < self::N_PATR; $k++) {
            $pr = 73 + $k;
            if (mb_strtoupper(trim($this->g("L{$pr}")), 'UTF-8') !== $key) continue;
            if ($this->g("B{$pr}") !== '') {
                $out[] = ['clase' => 'monetario', 'moneda' => $this->clave1($this->g("B{$pr}")), 'monto_operacion' => $this->g("C{$pr}")];
            } elseif ($this->g("D{$pr}") !== '') {
                $out[] = ['clase' => 'inmueble', 'tipo_inmueble' => $this->clave1($this->g("D{$pr}")), 'codigo_postal' => $this->g("E{$pr}"), 'folio_real' => $this->g("H{$pr}"), 'importe_garantia' => $this->g("I{$pr}")];
            } elseif ($this->g("J{$pr}") !== '') {
                $out[] = ['clase' => 'otro', 'descripcion' => $this->g("J{$pr}"), 'valor_bien' => $this->g("K{$pr}")];
            }
        }
        return $out;
    }

    /** Fideicomisario personas are shifted one column right (nombre at C, not B). */
    private function personaFisicaFid(int $r): array
    {
        return [
            'tipo' => 'fisica',
            'nombre' => $this->g("C{$r}"), 'apellido_paterno' => $this->g("D{$r}"), 'apellido_materno' => $this->g("E{$r}"),
            'fecha_nacimiento' => $this->g("F{$r}"), 'rfc' => $this->g("G{$r}"), 'curp' => $this->g("H{$r}"),
            'pais_nacionalidad' => $this->clave2($this->g("I{$r}")), 'actividad_economica' => $this->clave3($this->g("J{$r}")),
        ];
    }

    private function personaMoralFid(int $r): array
    {
        return [
            'tipo' => 'moral',
            'denominacion_razon' => $this->g("C{$r}"), 'fecha_constitucion' => $this->g("D{$r}"), 'rfc' => $this->g("E{$r}"),
            'pais_nacionalidad' => $this->clave2($this->g("F{$r}")), 'giro_mercantil' => $this->clave3($this->g("G{$r}")),
        ];
    }

    private function fideicomisoFid(int $r): array
    {
        return [
            'tipo' => 'fideicomiso',
            'denominacion_razon' => $this->g("C{$r}"), 'rfc' => $this->g("D{$r}"), 'identificador_fideicomiso' => $this->g("E{$r}"),
        ];
    }
}
