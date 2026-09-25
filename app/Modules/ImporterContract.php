<?php

namespace App\Modules;

/**
 * Optional per-module contract: parse a previously generated/submitted file
 * (the module's own export format) back into review form data.
 * Declared in module.json as "importer_class" (file: Importer.php).
 *
 * parse() receives text already decoded to UTF-8 with "\n" line endings
 * (see App\Services\Import\TxtDecoder). Throw ImportException when the file
 * is not this module's format.
 */
interface ImporterContract
{
    public function parse(string $text): ImportResult;
}
