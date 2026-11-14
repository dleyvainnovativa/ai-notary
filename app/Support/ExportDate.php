<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Single source of truth for date formatting in the TXT/XML exporters.
 *
 * The review form and importers always normalise dates to ISO (Y-m-d), so the
 * stored value is ISO in practice. The SAT/UIF files want dd/mm/yyyy. This
 * helper converts ISO → dd/mm/yyyy, is idempotent for values already in
 * dd/mm/yyyy (so re-exporting imported data stays byte-identical), accepts a
 * DateTimeInterface (e.g. a now() fallback), and falls back to Carbon for any
 * other parseable string. A blank/null value yields an empty string.
 *
 * Used by the UIF, DeclaraNOT (enajenación) and DeclaraNOT por adquisición
 * exporters so the three can never drift apart again.
 */
final class ExportDate
{
    /** Any supported input → "dd/mm/yyyy" (empty string when blank). */
    public static function dmy($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y');
        }
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return '';
        }
        // ISO date or datetime (the stored form value)
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return "{$m[3]}/{$m[2]}/{$m[1]}";
        }
        // Already dd/mm/yyyy → leave untouched (keeps import round-trips exact)
        if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $s)) {
            return $s;
        }
        return Carbon::parse($s)->format('d/m/Y');
    }

    /** Year (YYYY) of any supported input (empty string when blank/unknown). */
    public static function year($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y');
        }
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-\d{2}-\d{2}/', $s, $m)) {
            return $m[1];
        }
        if (preg_match('#^\d{2}/\d{2}/(\d{4})$#', $s, $m)) {
            return $m[1];
        }
        return Carbon::parse($s)->format('Y');
    }
}
