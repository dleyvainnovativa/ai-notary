<?php

namespace App\Services\Import;

use App\Modules\ExporterContract;
use App\Modules\ImporterContract;
use App\Modules\ImportResult;

/**
 * Decode → module importer → ROUND-TRIP CHECK: the parsed data is exported again
 * with the module's own exporter and compared line by line with the uploaded
 * file. If they match, re-exporting the unedited document reproduces the file
 * exactly; if not, the user is told which lines will change. Pure PHP.
 */
class ImportService
{
    public function import(string $bytes, ImporterContract $importer, ?ExporterContract $exporter = null, string $format = 'txt'): ImportResult
    {
        $text = TxtDecoder::decode($bytes);
        $result = $importer->parse($text);

        $result->meta['round_trip'] = null;
        if ($exporter) {
            try {
                $data = $result->data;
                if (!empty($result->meta['notaria'])) $data['_notaria'] = $result->meta['notaria'];
                $again = TxtDecoder::lines(TxtDecoder::decode($exporter->export($data, $format)));
                $orig = TxtDecoder::lines($text);

                $diff = [];
                $n = max(count($orig), count($again));
                for ($i = 0; $i < $n; $i++) {
                    if (($orig[$i] ?? null) !== ($again[$i] ?? null)) $diff[] = $i + 1;
                }
                $result->meta['round_trip'] = !$diff;
                if ($diff) {
                    $shown = implode(', ', array_slice($diff, 0, 8)) . (count($diff) > 8 ? '…' : '');
                    $result->warn(count($diff) . ' línea(s) del archivo cambiarán al volver a exportar (líneas ' . $shown . '). Revisa esos datos antes de enviarlo.');
                }
            } catch (\Throwable $e) {
                $result->warn('No se pudo verificar que el archivo se reproduzca al exportar: ' . $e->getMessage());
            }
        }
        return $result;
    }
}
