<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Modules\ModuleRegistry;
use App\Services\CatalogService;
use App\Services\Pdf\ReviewPdfPresenter;
use App\Services\Pdf\ReviewPdfRenderer;
use App\Services\Schema\SchemaEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentController extends Controller
{
    private const MAX_DRAFT_BYTES = 1_000_000; // ~1 MB of JSON; a UIF aviso is ~10 KB

    public function __construct(
        private ModuleRegistry $registry,
        private CatalogService $catalogs,
        private SchemaEngine $engine,
    ) {}

    /** Returns everything the JS review form needs to render. */
    public function reviewData(Document $document)
    {
        abort_unless($document->user_id === auth()->id(), 403);
        abort_unless(in_array($document->status, Document::REVIEWABLE, true), 409, 'El documento aún no está listo para revisión.');
        abort_unless($document->isReviewable(), 410, 'Los datos de este documento ya fueron eliminados por privacidad.');

        $module = $this->registry->controller($document->module_slug);
        $dir = $this->registry->moduleDir($document->module_slug);

        $formSchema = $module->formSchema();   // ← use formSchema for BOTH rendering and engine

        $raw = json_decode($document->ai_output_encrypted ?? '', true) ?: [];
        $notes = $raw['_meta']['notes'] ?? [];
        unset($raw['_meta']);

        // The user's saved draft wins over the AI output (AI output stays the diff baseline).
        $flat = $document->review_data_encrypted ?? $this->flatten($raw);

        $result = $this->engine->process($formSchema, $flat, $dir);   // ← formSchema, not schema()

        return response()->json([
            'schema' => $formSchema,
            'data' => $result->data,
            'issues' => collect($result->issues)->map(fn($i) => [
                'path' => $i->path,
                'field' => $i->field,
                'rule' => $i->rule,
                'message' => $i->message,
            ])->values(),
            'notes' => $this->notePaths($notes),
            'formats' => $this->registry->manifest($document->module_slug)['exports'] ?? ['txt'],
            'status' => $document->status,
            'draft' => $this->draftInfo($document),
            'append' => $this->appendConfig($document),
        ]);
    }

    /** Module's "append" block (operaciones acumuladas) for the form, or null. */
    private function appendConfig(Document $document): ?array
    {
        if ($document->status !== 'requires_review') return null;
        $cfg = $this->registry->manifest($document->module_slug)['append'] ?? null;
        if (!$cfg || empty($cfg['array'])) return null;
        return [
            'array' => $cfg['array'],
            'input' => $cfg['input'] ?? 'escritura',
            'enabled_when' => $cfg['enabled_when'] ?? [],
            'label' => $cfg['label'] ?? 'Agregar escritura',
        ];
    }

    /**
     * Autosave of the review form (raw form state, no engine run). Optimistic
     * lock: the client sends the version it loaded; a mismatch means another tab
     * (or an export) saved in between → 409, the client stops autosaving.
     */
    public function saveDraft(Request $request, Document $document)
    {
        abort_unless($document->user_id === auth()->id(), 403);
        abort_unless(in_array($document->status, Document::REVIEWABLE, true), 409, 'El documento no se puede editar.');

        $data = $request->input('data');
        abort_unless(is_array($data), 422, 'Datos inválidos.');
        abort_if(strlen(json_encode($data)) > self::MAX_DRAFT_BYTES, 413, 'El borrador es demasiado grande.');

        $clientVersion = (int) $request->input('version', -1);

        return DB::transaction(function () use ($document, $data, $clientVersion) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->first();

            if ($clientVersion !== (int) $locked->review_version) {
                return response()->json([
                    'message' => 'Este documento se guardó desde otra pestaña o sesión.',
                    'draft' => $this->draftInfo($locked),
                ], 409);
            }

            $locked->saveDraft($data);
            return response()->json(['draft' => $this->draftInfo($locked)]);
        });
    }

    /**
     * Review PDF (every module, one generic template).
     *  POST  data = current form state → rendered AND saved as the draft (like validate)
     *  GET   stored draft (or AI output) → e.g. from the dashboard; ?download=1 forces a download
     */
    public function pdf(Request $request, Document $document, ReviewPdfPresenter $presenter, ReviewPdfRenderer $renderer)
    {
        abort_unless($document->user_id === auth()->id(), 403);
        abort_unless(in_array($document->status, Document::REVIEWABLE, true), 409, 'El documento aún no está listo.');

        $module = $this->registry->controller($document->module_slug);
        $dir = $this->registry->moduleDir($document->module_slug);
        $manifest = $this->registry->manifest($document->module_slug);
        $formSchema = $module->formSchema();

        $raw = json_decode($document->ai_output_encrypted ?? '', true) ?: [];
        $notes = $this->notePaths($raw['_meta']['notes'] ?? []);

        if ($request->isMethod('post')) {
            $data = $request->input('data');
            abort_unless(is_array($data), 422, 'Datos inválidos.');
            $document->saveDraft($data);
        } else {
            abort_unless($document->isReviewable(), 410, 'Los datos de este documento ya fueron eliminados por privacidad.');
            unset($raw['_meta']);
            $data = $document->review_data_encrypted ?? $this->flatten($raw);
        }

        $data = $this->engine->process($formSchema, $data, $dir)->data;   // derived values (dates from CURP…)
        $sections = $presenter->present($formSchema, $data, $notes);

        $reference = (string) ($data['numero_escritura'] ?? $data['referencia_aviso'] ?? $document->id);
        $tz = config('app.display_timezone', 'America/Mexico_City');
        $doc = [
            'module' => $manifest['name'] ?? $document->module_slug,
            'reference' => $reference,
            'generated_at' => now()->timezone($tz)->format('d/m/Y H:i'),
            'status' => ['requires_review' => 'Por revisar', 'completed' => 'Exportado'][$document->status] ?? null,
            'notaria' => $this->notariaLine($document),
            'draft_saved_at' => $document->review_saved_at?->timezone($tz)->format('d/m/Y H:i'),
        ];

        $filename = $document->module_slug . '_' . preg_replace('/[^A-Za-z0-9_-]/', '', $reference) . '_revision.pdf';
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($renderer->pdf($doc, $sections), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, private',          // personal data: never cache
            'X-Draft-Version' => (string) $document->review_version,
        ]);
    }

    private function notariaLine(Document $document): ?string
    {
        $p = $document->user->notarioProfile;
        if (!$p) return null;
        $parts = array_filter([
            $p->num_notaria ? 'Notaría Pública No. ' . $p->num_notaria : null,
            $p->nombre_notario,
            $p->entidad_federativa,
        ], fn($v) => is_string($v) && trim($v) !== '');
        return $parts ? implode(' · ', $parts) : null;
    }

    private function draftInfo(Document $document): array
    {
        return [
            'version' => (int) $document->review_version,
            'saved_at' => $document->review_saved_at?->toIso8601String(),
        ];
    }

    /**
     * ReferenceResolver notes carry the input key separately; map them to the
     * same paths flatten() produces so the form can find the field.
     */
    private function notePaths(array $notes): array
    {
        return \App\Support\ReviewData::notePaths($notes);
    }

    /** Re-run the engine on submitted (corrected) data; authoritative validation + diffs. */
    public function reviewValidate(Request $request, Document $document)
    {
        abort_unless($document->user_id === auth()->id(), 403);
        abort_unless(in_array($document->status, Document::REVIEWABLE, true), 409, 'El documento no se puede editar.');

        $module = $this->registry->controller($document->module_slug);
        $dir = $this->registry->moduleDir($document->module_slug);
        // $schema = $module->schema();
        $schema = $module->formSchema();

        $submitted = $request->input('data', []);
        abort_unless(is_array($submitted), 422, 'Datos inválidos.');
        $result = $this->engine->process($schema, $submitted, $dir);

        // Explicit user action: always persist (no version check) and hand back the new version.
        $document->saveDraft($submitted);

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
            'draft' => $this->draftInfo($document),
        ]);
    }

    public function export(Request $request, Document $document)
    {
        abort_unless($document->user_id === auth()->id(), 403);

        $module = $this->registry->controller($document->module_slug);
        $dir = $this->registry->moduleDir($document->module_slug);
        // $schema = $module->schema();
        $schema = $module->formSchema();

        abort_unless(in_array($document->status, Document::REVIEWABLE, true), 409, 'El documento no se puede exportar.');

        $submitted = $request->input('data', []);
        abort_unless(is_array($submitted), 422, 'Datos inválidos.');
        $format = $request->input('format', 'txt');

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

        abort_unless(in_array($format, $exporter->supportedFormats(), true), 422, 'Formato no soportado.');

        // before calling $exporter->export(...)
        $profile = $document->user->notarioProfile;
        $result->data['_notaria'] = [
            'clave' => $profile->clave ?? '001',
            'entidad' => $profile->entidad ?? '035',
            'num_notaria' => $profile->num_notaria ?? '28',
        ];
        $content = $exporter->export($result->data, $format);

        // Mark the document completed + clear stored AI output (privacy: purge after export)
        // $document->update(['status' => 'completed', 'reviewed_at' => now(), 'ai_output_encrypted' => null]);
        $document->update(['status' => 'completed', 'reviewed_at' => now()]);
        $document->saveDraft($submitted);   // what was exported is what the draft now holds

        $ref = $result->data['numero_escritura']
            ?? $result->data['referencia_aviso']
            ?? $document->id;
        $filename = $document->module_slug . '_' . $ref . '.' . $format;
        $mime = $format === 'xml' ? 'application/xml' : 'text/plain';

        return response($content, 200, [
            'Content-Type' => $mime . '; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Draft-Version' => (string) $document->review_version,  // keeps the tab's autosave in sync
        ]);
    }

    private function flatten(?array $raw): array
    {
        return \App\Support\ReviewData::flatten($raw);
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

        $samplePath = $dir . '/debug_sample.json';
        if (file_exists($samplePath)) {
            $flat = json_decode(file_get_contents($samplePath), true);
        } else {
            $flat = [];
            foreach ($module->inputs() as $input) {
                $flat = array_merge($flat, $input->outputExample($dir));
            }
        }

        $result = $this->engine->process($formSchema, $flat, $dir);

        return response()->json([
            'schema' => $formSchema,
            'data' => $result->data,
            'issues' => collect($result->issues)->map(fn($i) => [
                'path' => $i->path,
                'field' => $i->field,
                'rule' => $i->rule,
                'message' => $i->message,
            ])->values(),
            'formats' => $this->registry->manifest($slug)['exports'] ?? ['txt'],
        ]);
    }
}
