<?php

namespace App\Services\Schema;

/**
 * Dates encoded in Mexican IDs. Pure PHP (no framework) so it is testable
 * standalone. Mirrored in resources/js/pages/review.js (deriveFromId) for the
 * live fill — keep both in sync.
 *
 *  CURP  AAAA YYMMDD H EEE CCC X D   → birth date; the 17th char (X) is a digit
 *                                      for births before 2000, a letter from 2000.
 *  RFC física  AAAA YYMMDD HHH       → birth date, century NOT encoded.
 *  RFC moral   AAA  YYMMDD HHH       → constitution date, century NOT encoded.
 */
class IdDates
{
    private const GENERIC_RFC = ['XAXX010101000', 'XEXX010101000', 'EXTF900101000', 'EXT990101000'];

    /** Exact birth date from a CURP, or null. */
    public static function fromCurp(?string $curp): ?string
    {
        $c = strtoupper(trim((string) $curp));
        if (!preg_match('/^[A-Z]{4}(\d{2})(\d{2})(\d{2})[HMX][A-Z]{5}([A-Z0-9])\d$/', $c, $m)) return null;

        $century = ctype_digit($m[4]) ? 1900 : 2000;
        return self::ymd($century + (int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /**
     * Birth date from a persona física RFC. Century is a guess: the 2000s unless
     * that would make the person younger than 18 today (parties to a deed are
     * normally adults; minors are covered by the CURP, which encodes the century).
     */
    public static function fromRfcFisica(?string $rfc, ?int $currentYear = null): ?string
    {
        $r = strtoupper(trim((string) $rfc));
        if (in_array($r, self::GENERIC_RFC, true)) return null;
        if (!preg_match('/^[A-ZÑ&]{4}(\d{2})(\d{2})(\d{2})[A-Z0-9]{3}$/u', $r, $m)) return null;

        $currentYear ??= (int) date('Y');
        $year = 2000 + (int) $m[1];
        if ($year > $currentYear - 18) $year -= 100;
        return self::ymd($year, (int) $m[2], (int) $m[3]);
    }

    /** Constitution date from a persona moral RFC (12 chars). Century: 2000s unless in the future. */
    public static function fromRfcMoral(?string $rfc, ?int $currentYear = null): ?string
    {
        $r = strtoupper(trim((string) $rfc));
        if (in_array($r, self::GENERIC_RFC, true)) return null;
        if (!preg_match('/^[A-ZÑ&]{3}(\d{2})(\d{2})(\d{2})[A-Z0-9]{3}$/u', $r, $m)) return null;

        $currentYear ??= (int) date('Y');
        $year = 2000 + (int) $m[1];
        if ($year > $currentYear) $year -= 100;
        return self::ymd($year, (int) $m[2], (int) $m[3]);
    }

    /**
     * Apply a formSchema 'derive' rule to one field.
     *   birthdate_from_id   ['curp' => 'curp', 'rfc' => 'rfc']
     *       CURP valid → ALWAYS wins (overwrites the deed/AI value).
     *       else RFC física valid → fills ONLY when empty (century is a guess).
     *   date_from_rfc_moral ['rfc' => 'rfc'] → fills only when empty.
     */
    public static function derive(array $rule, array $row, $current, ?int $currentYear = null)
    {
        $empty = $current === null || trim((string) $current) === '';

        switch ($rule['rule'] ?? null) {
            case 'birthdate_from_id':
                $fromCurp = self::fromCurp($row[$rule['curp'] ?? 'curp'] ?? null);
                if ($fromCurp) return $fromCurp;
                if ($empty) {
                    return self::fromRfcFisica($row[$rule['rfc'] ?? 'rfc'] ?? null, $currentYear) ?? $current;
                }
                return $current;

            case 'date_from_rfc_moral':
                if ($empty) {
                    return self::fromRfcMoral($row[$rule['rfc'] ?? 'rfc'] ?? null, $currentYear) ?? $current;
                }
                return $current;
        }
        return $current;
    }

    private static function ymd(int $y, int $m, int $d): ?string
    {
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }
}
