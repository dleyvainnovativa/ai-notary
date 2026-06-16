<?php

// app/Models/TokenTransaction.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TokenTransaction extends Model
{
    protected $fillable = ['type', 'tokens', 'stripe_payment_id', 'metadata_json'];
    protected $casts = ['metadata_json' => 'array'];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
