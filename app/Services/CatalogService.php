<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CatalogService
{
    /**
     * Inject catalog options into a schema.
     * Walks the schema recursively; wherever a field has "source": "<catalog>",
     * loads that catalog and adds an "allowed_values" array.
     *
     * @param array  $schema     The module's schema (decoded JSON)
     * @param string $moduleDir  Absolute path to the module version dir (has /catalogs)
     */
    public function inject(array $schema, string $moduleDir): array
    {
        return $this->walk($schema, $moduleDir);
    }

    private function walk(array $node, string $moduleDir): array
    {
        foreach ($node as $key => $value) {
            if (!is_array($value)) continue;

            // If this node declares a catalog source, attach its options
            if (isset($value['source']) && is_string($value['source'])) {
                $options = $this->load($value['source'], $moduleDir);
                if ($options !== null) {
                    $value['allowed_values'] = $options;
                }
            }

            // Recurse into nested structures (items, arrays of fields, etc.)
            $node[$key] = $this->walk($value, $moduleDir);
        }

        return $node;
    }

    /**
     * Load a catalog file from the module's /catalogs folder, cached.
     * Returns the array of {label, value}, or null if the file is missing.
     */
    public function load(string $catalogName, string $moduleDir): ?array
    {
        $path = "{$moduleDir}/catalogs/{$catalogName}.json";

        return Cache::remember(
            "catalog:" . md5($path),
            now()->addHours(6),
            function () use ($path) {
                if (!file_exists($path)) {
                    Log::warning("Catalog not found: {$path}");
                    return null;
                }
                $data = json_decode(file_get_contents($path), true);
                return is_array($data) ? $data : null;
            }
        );
    }

    /**
     * Validate a value against a catalog (used by Phase 5 validation layer).
     * Returns true if $value matches one of the catalog's "value" entries.
     */
    public function isValidValue(string $catalogName, string $moduleDir, $value): bool
    {
        $options = $this->load($catalogName, $moduleDir);
        if ($options === null) return false;

        foreach ($options as $opt) {
            if ((string) ($opt['value'] ?? null) === (string) $value) {
                return true;
            }
        }
        return false;
    }
}
