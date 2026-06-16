<?php

namespace App\Modules;

class ModuleInput
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $required,
        public string $promptPath,
        public string $schemaPath,
        public string $outputPath,
        public string $description = '',   // ← new
    ) {}

    public function prompt(string $moduleDir): string
    {
        return file_get_contents("{$moduleDir}/{$this->promptPath}");
    }
    public function schema(string $moduleDir): array
    {
        return json_decode(file_get_contents("{$moduleDir}/{$this->schemaPath}"), true);
    }
    public function outputExample(string $moduleDir): array
    {
        return json_decode(file_get_contents("{$moduleDir}/{$this->outputPath}"), true);
    }
}
