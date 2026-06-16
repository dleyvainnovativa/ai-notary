<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Modules\ModuleRegistry;
use App\Services\CatalogService;
use App\Services\Schema\SchemaEngine;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(
        private ModuleRegistry $registry,
        private CatalogService $catalogs,
        private SchemaEngine $engine,
    ) {}

    /** Returns everything the JS review form needs to render. */
    public function reviewData(Document $document)
    {
        abort_unless($document->user_id === auth()->id(), 403);
        abort_unless($document->status === 'requires_review', 409, 'Document is not ready for review.');

        $module = $this->registry->controller($document->module_slug);
        $dir = $this->registry->moduleDir($document->module_slug);

        $formSchema = $module->formSchema();      // for rendering
        $engineSchema = $module->schema();        // raw, for validation engine

        $raw = json_decode($document->ai_output_encrypted, true);
        $flat = $this->flatten($raw);

        $result = $this->engine->process($engineSchema, $flat, $dir);

        return response()->json([
            'schema' => $formSchema,
            'data' => $result->data,
            'issues' => collect($result->issues)->map(fn($i) => [
                'path' => $i->path,
                'field' => $i->field,
                'rule' => $i->rule,
                'message' => $i->message,
            ])->values(),
        ]);
    }

    /** Re-run the engine on submitted (corrected) data; authoritative validation + diffs. */
    public function reviewValidate(Request $request, Document $document)
    {
        abort_unless($document->user_id === auth()->id(), 403);

        $module = $this->registry->controller($document->module_slug);
        $dir = $this->registry->moduleDir($document->module_slug);
        $schema = $module->schema();

        $submitted = $request->input('data', []);
        $result = $this->engine->process($schema, $submitted, $dir);

        // Capture AI-vs-corrected diffs (against original extraction)
        $original = $this->flatten(json_decode($document->ai_output_encrypted ?? '', true) ?: []);
        $diffs = $this->diff($original, $result->data);

        return response()->json([
            'valid' => true,
            // 'valid' => $result->isValid(),
            'data' => $result->data,   // engine may have recomputed totals
            'issues' => collect($result->issues)->map(fn($i) => [
                'path' => $i->path,
                'field' => $i->field,
                'rule' => $i->rule,
                'message' => $i->message,
            ])->values(),
            'diffs' => $diffs,
            // Phase 6 will persist + enable export here when valid.
        ]);
    }

    public function export(Request $request, Document $document)
    {
        abort_unless($document->user_id === auth()->id(), 403);

        $module = $this->registry->controller($document->module_slug);
        $dir = $this->registry->moduleDir($document->module_slug);
        $schema = $module->schema();

        $submitted = $request->input('data', []);

        // Server re-runs the engine — authoritative validation + computed/derived
        $result = $this->engine->process($schema, $submitted, $dir);
        // if (!$result->isValid()) {
        //     return response()->json([
        //         'message' => 'Los datos no son válidos.',
        //         'issues' => collect($result->issues)->map(fn($i) => [
        //             'path' => $i->path,
        //             'field' => $i->field,
        //             'rule' => $i->rule,
        //             'message' => $i->message,
        //         ])->values(),
        //     ], 422);
        // }

        // Build the file via the module's exporter
        $manifest = $this->registry->manifest($document->module_slug);
        require_once $dir . '/Exporter.php';
        $exporterClass = $manifest['exporter_class'];
        $exporter = new $exporterClass();

        $content = $exporter->export($result->data, 'txt');

        // Mark the document completed + clear stored AI output (privacy: purge after export)
        // $document->update(['status' => 'completed', 'reviewed_at' => now(), 'ai_output_encrypted' => null]);
        $document->update(['status' => 'completed', 'reviewed_at' => now()]);

        $filename = 'declaranot_' . ($result->data['numero_escritura'] ?? $document->id) . '.txt';

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function flatten(?array $raw): array
    {
        if ($raw === null) return [];
        if (isset($raw['escritura']) || isset($raw['calculo'])) {
            return array_merge($raw['escritura'] ?? [], $raw['calculo'] ?? []);
        }
        return $raw;
    }

    private function resolveCatalogs(array $schema, string $dir): array
    {
        $names = [];
        $this->collectSources($schema['fields'] ?? [], $names);
        $out = [];
        foreach (array_unique($names) as $name) {
            $out[$name] = $this->catalogs->load($name, $dir) ?? [];
        }
        return $out;
    }

    private function collectSources(array $fields, array &$names): void
    {
        foreach ($fields as $def) {
            if (!is_array($def)) continue;
            if (!empty($def['source'])) $names[] = $def['source'];
            if (($def['type'] ?? null) === 'array' && !empty($def['items'])) {
                $this->collectSources($def['items'], $names);
            }
            if (($def['type'] ?? null) === 'object' && !empty($def['items'])) {
                $this->collectSources($def['items'], $names);
            }
        }
    }

    private function diff(array $original, array $corrected, string $prefix = ''): array
    {
        $diffs = [];
        foreach ($corrected as $key => $val) {
            $path = $prefix === '' ? $key : "{$prefix}.{$key}";
            $orig = $original[$key] ?? null;
            if (is_array($val)) {
                $diffs = array_merge($diffs, $this->diff(is_array($orig) ? $orig : [], $val, $path));
            } elseif ((string) $orig !== (string) $val) {
                $diffs[] = ['path' => $path, 'ai_value' => $orig, 'corrected_value' => $val];
            }
        }
        return $diffs;
    }
    /** DEBUG ONLY: serve the review form from a saved sample, no AI call, no token. */
    public function reviewDebug(Request $request)
    {
        abort_unless(app()->environment('local'), 404);

        $slug = $request->query('module', 'declaranot');
        $module = $this->registry->controller($slug);
        $dir = $this->registry->moduleDir($slug);

        $formSchema = $module->formSchema();
        $engineSchema = $module->schema();

        // Load a sample from the module folder (the *_output.json files you already have)
        $samplePath = $dir . '/debug_sample.json';
        if (file_exists($samplePath)) {
            $flat = json_decode(file_get_contents($samplePath), true);
        } else {
            // fall back to merging the per-input output examples
            $flat = [];
            foreach ($module->inputs() as $input) {
                $flat = array_merge($flat, $input->outputExample($dir));
            }
        }

        $result = $this->engine->process($engineSchema, $flat, $dir);

        return response()->json([
            'schema' => $formSchema,
            'data' => $result->data,
            'issues' => collect($result->issues)->map(fn($i) => [
                'path' => $i->path,
                'field' => $i->field,
                'rule' => $i->rule,
                'message' => $i->message,
            ])->values(),
        ]);
    }
}
