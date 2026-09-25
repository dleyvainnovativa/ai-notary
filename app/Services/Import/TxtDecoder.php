<?php

namespace App\Services\Import;

/**
 * SAT/UIF text files come as Windows-1252 + CRLF (our UIF export) or UTF-8 + LF
 * (declaranot export, files edited by hand). Normalize to UTF-8 + "\n".
 */
class TxtDecoder
{
    public static function decode(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) $bytes = substr($bytes, 3);   // UTF-8 BOM
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }
        return str_replace(["\r\n", "\r"], "\n", $bytes);
    }

    /** Non-empty lines of decoded text, in order. */
    public static function lines(string $text): array
    {
        return array_values(array_filter(explode("\n", $text), fn($l) => trim($l) !== ''));
    }

    /** "TAG-Descripción:payload" → [tag, payload]; "Configuracion:x" → ['Configuracion', x]; else null. */
    public static function splitLine(string $line): ?array
    {
        $pos = strpos($line, ':');
        if ($pos === false) return null;
        $head = substr($line, 0, $pos);
        $payload = substr($line, $pos + 1);
        if ($head === 'Configuracion') return ['Configuracion', $payload];
        if (preg_match('/^(\d{6})-/', $head, $m)) return [$m[1], $payload];
        return null;
    }

    /** d/m/Y → Y-m-d; '' → null; anything else returned unchanged (caller may warn). */
    public static function ymd(?string $dmy): ?string
    {
        $dmy = trim((string) $dmy);
        if ($dmy === '') return null;
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $dmy, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return $dmy;
    }

    public static function nullIfEmpty(?string $v): ?string
    {
        return ($v === null || $v === '') ? null : $v;
    }
}
