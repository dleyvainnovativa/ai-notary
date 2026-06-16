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
