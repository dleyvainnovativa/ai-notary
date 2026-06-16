<?php

namespace App\Modules;

class ModuleRegistry
{
    private string $basePath;

    public function __construct()
    {
        $this->basePath = base_path('modules');
    }

    /** All active modules' manifests, for UI dropdowns etc. */
    public function active(): array
    {
        $modules = [];
        foreach (glob($this->basePath . '/*', GLOB_ONLYDIR) as $dir) {
            $latest = $this->latestVersionDir($dir);
            if (!$latest) continue;
            $manifest = json_decode(file_get_contents($latest . '/module.json'), true);
            if ($manifest['active'] ?? false) {
                $modules[$manifest['slug']] = $manifest;
            }
        }
        return $modules;
    }

    // private function moduleDir(string $slug, ?string $version): string
    // {
    //     $base = $this->basePath . '/' . $slug;
    //     $dir = $version ? "{$base}/v{$version}" : $this->latestVersionDir($base);

    //     if (!$dir || !is_dir($dir)) {
    //         throw new \RuntimeException("Module not found: {$slug}" . ($version ? " v{$version}" : ''));
    //     }
    //     return $dir;
    // }

    /** Public: expose the module directory path (used by ProcessDocumentJob). */
    public function moduleDir(string $slug, ?string $version = null): string
    {
        return $this->resolveDir($slug, $version);
    }

    public function controller(string $slug, ?string $version = null): ModuleControllerContract
    {
        $dir = $this->resolveDir($slug, $version);
        $manifest = json_decode(file_get_contents($dir . '/module.json'), true);

        require_once $dir . '/Controller.php';
        $class = $manifest['controller_class'];

        return new $class($dir);
    }

    public function manifest(string $slug, ?string $version = null): array
    {
        return json_decode(file_get_contents($this->resolveDir($slug, $version) . '/module.json'), true);
    }

    /** Private: resolve the on-disk directory for a slug (+ optional pinned version). */
    private function resolveDir(string $slug, ?string $version): string
    {
        $base = $this->basePath . '/' . $slug;
        $dir = $version ? "{$base}/v{$version}" : $this->latestVersionDir($base);

        if (!$dir || !is_dir($dir)) {
            throw new \RuntimeException("Module not found: {$slug}" . ($version ? " v{$version}" : ''));
        }
        return $dir;
    }

    private function latestVersionDir(string $moduleBase): ?string
    {
        $versions = glob($moduleBase . '/v*', GLOB_ONLYDIR);
        if (empty($versions)) return null;
        natsort($versions);
        return end($versions);
    }
}
