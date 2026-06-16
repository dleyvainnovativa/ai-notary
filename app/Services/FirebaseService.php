<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Auth as FirebaseAuth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use App\Models\User;

class FirebaseService
{
    private FirebaseAuth $auth;

    public function __construct()
    {
        $this->auth = (new Factory)
            ->withServiceAccount(base_path(config('firebase.credentials')))
            ->createAuth();
    }

    /**
     * Verify a Firebase ID token. Returns the decoded claims or throws.
     * checkIfRevoked = true ensures disabled/revoked tokens are rejected.
     */
    public function verifyIdToken(string $idToken): array
    {
        try {
            $verified = $this->auth->verifyIdToken($idToken, true);
            return [
                'uid'      => $verified->claims()->get('sub'),
                'email'    => $verified->claims()->get('email'),
                'name'     => $verified->claims()->get('name'),
                'picture'  => $verified->claims()->get('picture'),
                'provider' => $verified->claims()->get('firebase')['sign_in_provider'] ?? null,
            ];
        } catch (FailedToVerifyToken $e) {
            throw new \RuntimeException('Invalid Firebase token', 401, $e);
        }
    }

    /**
     * Find or create the local user record from verified Firebase claims.
     */
    public function syncUser(array $claims): User
    {
        return User::updateOrCreate(
            ['firebase_uid' => $claims['uid']],
            [
                'email'         => $claims['email'],
                'name'          => $claims['name'] ?? $claims['email'],
                'avatar_url'    => $claims['picture'] ?? null,
                'auth_provider' => $claims['provider'] ?? null,
                'last_login_at' => now(),
            ]
        );
    }
}
