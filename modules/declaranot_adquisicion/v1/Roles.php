<?php

namespace Modules\DeclaranotAdquisicion\V1;

/**
 * DeclaraNOT por adquisición de bienes = the DeclaraNOT layout with the persona
 * records switched: 900003 carries the ADQUIRIENTE(S) and 900005 the ENAJENANTE(S).
 * Everything else (900001/02/04/06/07/09/10/11/13) is identical.
 *
 * The form keeps the deed's real roles (who sells / who buys); only the file
 * swaps them. The swap is its own inverse, so export and import share it.
 */
final class Roles
{
    public static function swap(array $data): array
    {
        $enajenantes = $data['enajenantes'] ?? [];
        $adquirientes = $data['adquirientes'] ?? [];
        $data['enajenantes'] = array_map(fn($p) => self::renameTipo($p, 'tipo_adquiriente', 'tipo_enajenante'), is_array($adquirientes) ? $adquirientes : []);
        $data['adquirientes'] = array_map(fn($p) => self::renameTipo($p, 'tipo_enajenante', 'tipo_adquiriente'), is_array($enajenantes) ? $enajenantes : []);
        return $data;
    }

    private static function renameTipo($row, string $from, string $to)
    {
        if (!is_array($row)) return $row;
        $out = [];
        foreach ($row as $k => $v) $out[$k === $from ? $to : $k] = $v;   // keep column position
        if (!array_key_exists($to, $out)) $out = [$to => null] + $out;
        return $out;
    }
}
