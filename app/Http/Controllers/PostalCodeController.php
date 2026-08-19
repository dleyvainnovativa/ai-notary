<?php
// app/Http/Controllers/PostalCodeController.php

namespace App\Http\Controllers;

use App\Models\PostalCode;

class PostalCodeController extends Controller
{
    public function search(string $cp)
    {
        $cp = preg_replace('/\D/', '', $cp);
        if (strlen($cp) !== 5) {
            return response()->json(['colonias' => []]);
        }

        $colonias = PostalCode::where('codigo_postal', $cp)
            ->orderBy('colonia')
            ->pluck('colonia')
            ->unique()
            ->values()
            ->map(fn($name) => ['label' => $name, 'value' => $name]);

        return response()->json(['colonias' => $colonias]);
    }
}
