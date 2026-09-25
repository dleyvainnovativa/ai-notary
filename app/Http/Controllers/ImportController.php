<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Modules\ImportException;
use App\Modules\ModuleRegistry;
use App\Services\Import\ImportService;
use Illuminate\Http\Request;

/**
 * Import a TXT previously generated (by NotarIA or another tool) back into the
 * review form: edit it, preview the PDF, export again. No AI → no token.
 */
class ImportController extends Controller
{
    private const MAX_KB = 2048;

    public function store(Request $request, ModuleRegistry $registry, ImportService $imports)
    {
        $request->validate([
            'module' => ['required', 'string'],
            'file' => ['required', 'file', 'max:' . self::MAX_KB],
        ]);
        $slug = $request->input('module');
        $file = $request->file('file');

        if (strtolower($file->getClientOriginalExtension()) !== 'txt') {
            return response()->json(['message' => 'Sube el archivo .txt del aviso o declaración.'], 422);
        }

        $manifest = $registry->active()[$slug] ?? null;
        abort_unless($manifest, 422, 'Módulo no válido.');
        if (empty($manifest['importer_class'])) {
            return response()->json(['message' => 'Este módulo aún no permite importar archivos TXT.'], 422);
        }

        $dir = $registry->moduleDir($slug);
        require_once $dir . '/Importer.php';
        require_once $dir . '/Exporter.php';
        $importer = new ($manifest['importer_class'])();
        $exporter = !empty($manifest['exporter_class']) ? new ($manifest['exporter_class'])() : null;

        try {
            $result = $imports->import(file_get_contents($file->getRealPath()), $importer, $exporter);
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Field-level warnings → review notes; file-level ones → banner at the top of the form
        $notes = [];
        $banners = [];
        foreach ($result->warnings as $w) {
            if (!empty($w['path'])) {
                $notes[] = ['input' => 'escritura', 'path' => $w['path'], 'kind' => 'import', 'message' => $w['message']];
            } else {
                $banners[] = $w['message'];
            }
        }
        if (!empty($result->meta['notaria'])) {
            $n = $result->meta['notaria'];
            $p = $request->user()->notarioProfile;
            if ($p && ((string) $p->num_notaria !== (string) $n['num_notaria'] || (string) $p->entidad !== (string) $n['entidad'])) {
                $banners[] = "El archivo es de la notaría {$n['num_notaria']} (entidad {$n['entidad']}); al exportar se usarán los datos de tu perfil de notario.";
            }
        }
        if ($result->meta['round_trip'] === true) {
            array_unshift($banners, 'Archivo importado. Si lo exportas sin cambios, se genera exactamente el mismo TXT.');
        }

        $document = Document::create([
            'user_id' => $request->user()->id,
            'reservation_id' => null,
            'module_slug' => $slug,
            'module_version' => $manifest['version'] ?? null,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => 'text/plain',
            'size_bytes' => (int) $file->getSize(),
            'inputs_json' => null,
            'temp_path' => null,
            'status' => 'requires_review',
            // Stored like an AI result so review / diffs / PDF / export all work unchanged.
            'ai_output_encrypted' => json_encode([
                'escritura' => $result->data,
                '_meta' => ['notes' => $notes, 'banners' => $banners, 'source' => 'txt_import'],
            ]),
        ]);

        return response()->json([
            'document_id' => $document->id,
            'redirect' => route('upload', ['document' => $document->id]),
            'warnings' => count($result->warnings),
        ]);
    }
}
