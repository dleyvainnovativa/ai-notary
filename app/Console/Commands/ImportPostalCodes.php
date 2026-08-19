<?php
// app/Console/Commands/ImportPostalCodes.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportPostalCodes extends Command
{
    protected $signature = 'uif:import-postal-codes {path} {--truncate}';
    protected $description = 'Import SAT postal code → colonia CSV into postal_codes';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (!file_exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        if ($this->option('truncate')) {
            DB::table('postal_codes')->truncate();
            $this->info('Table truncated.');
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            $this->error('Could not open file.');
            return self::FAILURE;
        }

        $header = fgetcsv($handle);           // skip header row
        $batch = [];
        $count = 0;
        $batchSize = 1000;

        while (($row = fgetcsv($handle)) !== false) {
            // columns: [0]=c_Colonia, [1]=c_CodigoPostal, [2]=Nombre del asentamiento
            $cp = trim($row[1] ?? '');
            $colonia = trim($row[2] ?? '');
            if ($cp === '' || $colonia === '') continue;

            $batch[] = [
                'codigo_postal' => str_pad($cp, 5, '0', STR_PAD_LEFT),
                'colonia_code' => trim($row[0] ?? '') ?: null,
                'colonia' => $colonia,
            ];
            $count++;

            if (count($batch) >= $batchSize) {
                DB::table('postal_codes')->insert($batch);
                $batch = [];
                $this->output->write("\rImported {$count}…");
            }
        }
        if ($batch) DB::table('postal_codes')->insert($batch);
        fclose($handle);

        $this->info("\nDone. Imported {$count} rows.");
        return self::SUCCESS;
    }
}
