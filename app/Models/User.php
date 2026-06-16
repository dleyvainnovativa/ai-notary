<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'firebase_uid',
        'name',
        'email',
        'avatar_url',
        'auth_provider',
        'last_login_at',
        'organization_id',
    ];

    // No password — Firebase owns auth. Hide nothing sensitive locally.
    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return ['last_login_at' => 'datetime'];
    }

    public function tokenTransactions()
    {
        return $this->hasMany(TokenTransaction::class);
    }
}
