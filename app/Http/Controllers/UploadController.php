<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractTextJob;
use App\Models\Document;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    public function __construct(private TokenService $tokens) {}

    public function store(Request $request)
    {
        $user = $request->user();
        $registry = app(\App\Modules\ModuleRegistry::class);

        $slug = $request->validate(['module' => 'required|string'])['module'];
        $module = $registry->controller($slug);
        $inputs = $module->inputs();

        // Build validation rules from the module's declared inputs
        $rules = [];
        foreach ($inputs as $input) {
            $rules[$input->key] = [
                $input->required ? 'required' : 'nullable',
                'file',
                'mimetypes:' . implode(',', config('documents.accepted_mimes')),
                'max:' . (config('documents.max_size_bytes') / 1024),
            ];
        }
        $request->validate($rules);

        // Reserve ONE token for the whole logical document
        try {
            $reservation = $this->tokens->reserve($user, ['module' => $slug]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'You have no tokens left.'], 402);
        }

        // Store each provided file to temp, recording which input key it is
        $storedInputs = [];
        foreach ($inputs as $input) {
            if (!$request->hasFile($input->key)) continue;
            $file = $request->file($input->key);
            $storedInputs[$input->key] = [
                'temp_path' => $file->store(config('documents.temp_dir'), config('documents.temp_disk')),
                'mime' => $file->getMimeType(),
                'filename' => $file->getClientOriginalName(),
            ];
        }

        $document = Document::create([
            'user_id' => $user->id,
            'reservation_id' => $reservation->id,
            'module_slug' => $slug,
            'original_filename' => $storedInputs[$inputs[0]->key]['filename'] ?? 'document',
            'mime_type' => $storedInputs[$inputs[0]->key]['mime'] ?? '',
            'size_bytes' => 0,
            'inputs_json' => $storedInputs,   // map of key => {temp_path, mime, filename}
            'temp_path' => null,              // now per-input; see migration note
            'status' => 'uploaded',
        ]);

        $reservation->update(['context_json' => ['document_id' => $document->id]]);

        ExtractTextJob::dispatch($document->id);

        return response()->json(['document_id' => $document->id, 'status' => 'uploaded']);
    }

    /**
     * Operaciones acumuladas: upload another deed whose operation(s) get appended
     * to this document's review. The current form state is saved first so no edit
     * is lost; the new deed costs one token (released if processing or merging fails).
     */
    public function append(Request $request, Document $document)
    {
        abort_unless($document->user_id === $request->user()->id, 403);
        abort_unless($document->parent_document_id === null, 422, 'Agrega la escritura al documento principal.');
        abort_unless($document->status === 'requires_review', 409, 'El documento ya no está en revisión.');

        $registry = app(\App\Modules\ModuleRegistry::class);
        $config = $registry->manifest($document->module_slug)['append'] ?? null;
        abort_unless($config && !empty($config['array']), 422, 'Este módulo no admite escrituras adicionales.');
        $inputKey = $config['input'] ?? 'escritura';

        $request->validate([
            $inputKey => [
                'required', 'file',
                'mimetypes:' . implode(',', config('documents.accepted_mimes')),
                'max:' . (config('documents.max_size_bytes') / 1024),
            ],
            'data' => ['nullable', 'string'],
        ]);

        // Save what's on screen before the merge changes the draft underneath it.
        $data = json_decode((string) $request->input('data', ''), true);
        if (is_array($data)) {
            $document->saveDraft($data);
        }

        try {
            $reservation = $this->tokens->reserve($request->user(), ['module' => $document->module_slug, 'append_to' => $document->id]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'No tienes tokens disponibles.'], 402);
        }

        $file = $request->file($inputKey);
        $child = Document::create([
            'user_id' => $request->user()->id,
            'reservation_id' => $reservation->id,
            'parent_document_id' => $document->id,
            'module_slug' => $document->module_slug,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => 0,
            'inputs_json' => [$inputKey => [
                'temp_path' => $file->store(config('documents.temp_dir'), config('documents.temp_disk')),
                'mime' => $file->getMimeType(),
                'filename' => $file->getClientOriginalName(),
            ]],
            'temp_path' => null,
            'status' => 'uploaded',
        ]);
        $reservation->update(['context_json' => ['document_id' => $child->id, 'append_to' => $document->id]]);

        ExtractTextJob::dispatch($child->id);

        return response()->json([
            'document_id' => $child->id,
            'status' => 'uploaded',
            'draft' => ['version' => (int) $document->review_version],
        ]);
    }

    /** Lightweight status poll for the frontend. */
    public function status(Document $document)
    {
        abort_unless($document->user_id === auth()->id(), 403);

        return response()->json([
            'status' => $document->status,
            'error' => $document->last_error,
            'filename' => $document->original_filename,
        ]);
    }
}
