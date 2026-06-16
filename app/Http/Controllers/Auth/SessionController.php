<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    public function __construct(private FirebaseService $firebase) {}

    /** Exchange a verified Firebase ID token for a Laravel session. */
    public function store(Request $request)
    {
        $request->validate(['idToken' => 'required|string']);

        try {
            $claims = $this->firebase->verifyIdToken($request->idToken);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Authentication failed'], 401);
        }

        $user = $this->firebase->syncUser($claims);

        Auth::login($user, remember: true);
        $request->session()->regenerate(); // prevent session fixation

        return response()->json(['redirect' => route('dashboard')]);
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['redirect' => route('login')]);
    }
}
