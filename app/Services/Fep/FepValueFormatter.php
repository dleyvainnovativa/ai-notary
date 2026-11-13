<?php

namespace App\Services\Fep;

/**
 * Value coercion for the FEP XML (namespace .../recepcion/fep).
 *
 * The SAT FEP schema is strict and differs from the UIF TXT rules:
 *  - <nombre>/<apellido_*>  → [A-ZÑ ]{1,200}  (UPPERCASE, no accents, no digits)
 *  - <denominacion_razon>   → [A-ZÑ\d #\-\.&,_@'()]{1,254}  (uppercase + limited punctuation)
 *  - fechas                 → AAAAMMDD (8 digits)
 *  - montos                 → \d{1,14}\.\d{2}  (always 2 decimals, no sign, no thousands sep)
 *  - rfc física 13 / moral 12, curp 18, país 2 letras, actividad/giro 7 dígitos
 *
 * Every method is pure + static; no Laravel runtime needed (so the manual
 * tests can exercise it directly). Returns '' when a value can't be produced,
 * so the XML writer can decide whether to omit an optional element.
 */
final class FepValueFormatter
{
    /** When a person has no apellido paterno/materno the SAT wants four equis. */
    public const SIN_APELLIDO = 'XXXX';

    /** á é í ó ú ü and friends → ASCII; ñ/Ñ kept as Ñ (allowed by the schema). */
    private const ACCENTS = [
        'Á'=>'A','À'=>'A','Ä'=>'A','Â'=>'A','Ã'=>'A','Å'=>'A','á'=>'A','à'=>'A','ä'=>'A','â'=>'A','ã'=>'A','å'=>'A',
        'É'=>'E','È'=>'E','Ë'=>'E','Ê'=>'E','é'=>'E','è'=>'E','ë'=>'E','ê'=>'E',
        'Í'=>'I','Ì'=>'I','Ï'=>'I','Î'=>'I','í'=>'I','ì'=>'I','ï'=>'I','î'=>'I',
        'Ó'=>'O','Ò'=>'O','Ö'=>'O','Ô'=>'O','Õ'=>'O','ó'=>'O','ò'=>'O','ö'=>'O','ô'=>'O','õ'=>'O',
        'Ú'=>'U','Ù'=>'U','Ü'=>'U','Û'=>'U','ú'=>'U','ù'=>'U','ü'=>'U','û'=>'U',
        'Ñ'=>'Ñ','ñ'=>'Ñ','Ç'=>'C','ç'=>'C',
    ];

    /** Transliterate accents to ASCII, keep Ñ, then uppercase. */
    public static function deaccentUpper(?string $s): string
    {
        $s = (string) $s;
        $s = strtr($s, self::ACCENTS);
        return mb_strtoupper(trim($s), 'UTF-8');
    }

    /** <nombre>, <apellido_paterno>, <apellido_materno>: [A-ZÑ ] only, collapsed spaces. */
    public static function nombre(?string $s): string
    {
        $s = self::deaccentUpper($s);
        $s = preg_replace('/[^A-ZÑ ]/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /** Apellido with the SIN_APELLIDO fallback; $other guards the "both XXXX" rule. */
    public static function apellido(?string $s, ?string $other = null): string
    {
        $v = self::nombre($s);
        if ($v !== '') return $v;
        // VC*R: if the sibling apellido is already XXXX, this one must differ → use a space-safe marker.
        return self::nombre($other) === self::SIN_APELLIDO ? 'XXX' : self::SIN_APELLIDO;
    }

    /** <denominacion_razon>: uppercase, keep the schema's punctuation set. */
    public static function denominacion(?string $s): string
    {
        $s = self::deaccentUpper($s);
        $s = preg_replace("/[^A-ZÑ0-9 #\\-\\.&,_@'()]/u", ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /** <descripcion> fields: pattern [A-ZÑ\d \-.,':/$] (uppercase). */
    public static function descripcion(?string $s): string
    {
        $s = self::deaccentUpper($s);
        $s = preg_replace("/[^A-ZÑ0-9 \\-\\.,':\\/\\$]/u", ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /** RFC uppercased + trimmed; '' if it doesn't look like one (len 12/13). */
    public static function rfc(?string $s): string
    {
        $s = strtoupper(trim((string) $s));
        return (strlen($s) === 12 || strlen($s) === 13) ? $s : '';
    }

    /** Persona física RFC: exactly 13 chars, else '' (so an optional element is omitted). */
    public static function rfcFisica(?string $s): string
    {
        $s = strtoupper(trim((string) $s));
        return strlen($s) === 13 ? $s : '';
    }

    /** Persona moral / fideicomiso RFC: exactly 12 chars, else ''. */
    public static function rfcMoral(?string $s): string
    {
        $s = strtoupper(trim((string) $s));
        return strlen($s) === 12 ? $s : '';
    }

    public static function curp(?string $s): string
    {
        $s = strtoupper(trim((string) $s));
        return strlen($s) === 18 ? $s : '';
    }

    /** 2-letter país code, uppercase. '' if not two letters. */
    public static function pais(?string $s): string
    {
        $s = strtoupper(trim((string) $s));
        return preg_match('/^[A-Z]{2}$/', $s) ? $s : '';
    }

    /** 7-digit catalog code (actividad económica / giro). '' if not 7 digits. */
    public static function digito7(?string $s): string
    {
        $s = preg_replace('/\D/', '', (string) $s);
        return strlen($s) === 7 ? $s : '';
    }

    /** Accepts AAAAMMDD, YYYY-MM-DD, DD/MM/YYYY, a timestamp… → AAAAMMDD or ''. */
    public static function fecha($v): string
    {
        $s = trim((string) $v);
        if ($s === '') return '';
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
            return self::validDate($m[1], $m[2], $m[3]) ? $s : '';
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $s, $m)) {   // YYYY-MM-DD
            return self::validDate($m[1], $m[2], $m[3]) ? sprintf('%04d%02d%02d', $m[1], $m[2], $m[3]) : '';
        }
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})/', $s, $m)) {   // DD/MM/YYYY
            return self::validDate($m[3], $m[2], $m[1]) ? sprintf('%04d%02d%02d', $m[3], $m[2], $m[1]) : '';
        }
        return '';
    }

    private static function validDate($y, $mo, $d): bool
    {
        $y = (int) $y; $mo = (int) $mo; $d = (int) $d;
        return $mo >= 1 && $mo <= 12 && $d >= 1 && checkdate($mo, $d, $y) && $y >= 1900;
    }

    /** <mes_reportado>: AAAAMM. Accepts AAAAMM, YYYY-MM. '' if invalid. */
    public static function mesReportado($v): string
    {
        $s = preg_replace('/\D/', '', (string) $v);
        if (preg_match('/^(\d{4})(\d{2})$/', $s, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) return $s;
        return '';
    }

    /** Monto → \d{1,14}\.\d{2}. Non-numeric or negative → '0.00'. */
    public static function monto($v): string
    {
        if ($v === null || $v === '') return '0.00';
        $n = is_numeric($v) ? (float) $v : (float) preg_replace('/[^0-9.\-]/', '', (string) $v);
        if (!is_finite($n) || $n < 0) $n = 0.0;
        if ($n > 99999999999999.99) $n = 99999999999999.99;
        return number_format($n, 2, '.', '');
    }

    /** SI / NO from a boolean-ish value or an explicit "SI"/"NO". */
    public static function siNo($v): string
    {
        if (is_string($v)) {
            $u = strtoupper(trim($v));
            if (in_array($u, ['SI', 'NO'], true)) return $u;
            if (in_array($u, ['1', 'SÍ', 'TRUE', 'VERDADERO'], true)) return 'SI';
            if (in_array($u, ['0', 'FALSE', 'FALSO'], true)) return 'NO';
        }
        return $v ? 'SI' : 'NO';
    }

    /** Digits-only, trimmed to a max length; '' when empty. Used for 1-digit selectors. */
    public static function digits($v, ?int $max = null): string
    {
        $s = preg_replace('/\D/', '', (string) $v);
        if ($s === '') return '';
        return $max ? substr($s, 0, $max) : $s;
    }
}
