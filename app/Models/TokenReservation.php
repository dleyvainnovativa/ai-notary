<?php

// app/Models/TokenReservation.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TokenReservation extends Model
{
    protected $fillable = ['user_id', 'transaction_id', 'status', 'expires_at', 'context_json'];
    protected $casts = ['expires_at' => 'datetime', 'context_json' => 'array'];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function transaction()
    {
        return $this->belongsTo(TokenTransaction::class, 'transaction_id');
    }
}
