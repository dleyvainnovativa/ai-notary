<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessingCost extends Model
{
    protected $fillable = [
        'document_id',
        'user_id',
        'module_slug',
        'provider',
        'model',
        'tokens_in',
        'tokens_out',
        'cost_usd',
        'latency_ms',
    ];
}
