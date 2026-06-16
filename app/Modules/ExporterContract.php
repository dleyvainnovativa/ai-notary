<?php

namespace App\Modules;

interface ExporterContract
{
    public function supportedFormats(): array;
    public function export(array $validatedData, string $format): string;
}
