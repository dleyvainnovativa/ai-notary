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

    /**
     * Full system prompt = shared core rules (resources/prompts/common_rules.txt)
     * + this module's own prompt. Every module, current and future, inherits
     * the shared rules (references like "en esta fecha" / "mismo domicilio").
     */
    public function prompt(string $moduleDir): string
    {
        $common = @file_get_contents(resource_path('prompts/common_rules.txt')) ?: '';
        $own = file_get_contents("{$moduleDir}/{$this->promptPath}");

        return $common === '' ? $own : rtrim($common) . "\n\n" . $own;
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
