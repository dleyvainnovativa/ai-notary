<?php

namespace App\Services\Fep;

/**
 * Abstraction over "read cell A1 of the data sheet". Decouples the map logic
 * (FepXlsmReader) from PhpSpreadsheet so the reader is unit-testable with an
 * in-memory grid and the heavy library is only touched by the real binding.
 */
interface FepXlsmCellSource
{
    /** Raw cell value as a trimmed string ('' when empty). $addr like "C26". */
    public function get(string $addr): string;
}

/** In-memory source for tests: ['C26' => '1,Inmueble', ...]. */
final class ArrayCellSource implements FepXlsmCellSource
{
    public function __construct(private array $cells) {}

    public function get(string $addr): string
    {
        return trim((string) ($this->cells[$addr] ?? ''));
    }
}

/**
 * PhpSpreadsheet-backed source. Only instantiated at runtime (needs
 * phpoffice/phpspreadsheet). The data sheet is "Persona Objeto del aviso".
 *
 * Usage:
 *   $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
 *   $src = new SpreadsheetCellSource($ss->getSheetByName('Persona Objeto del aviso'));
 */
final class SpreadsheetCellSource implements FepXlsmCellSource
{
    /** @param object $sheet a PhpOffice\PhpSpreadsheet\Worksheet\Worksheet */
    public function __construct(private object $sheet) {}

    public function get(string $addr): string
    {
        // getCell(...)->getFormattedValue() keeps dates/strings as the user sees them.
        $cell = $this->sheet->getCell($addr);
        return trim((string) $cell->getFormattedValue());
    }
}
