<?php

// app/Models/StripeEvent.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeEvent extends Model
{
    protected $fillable = ['id', 'type', 'processed_at'];
    protected $casts = ['processed_at' => 'datetime'];

    // The primary key is Stripe's event id (evt_...), a string, not auto-incrementing
    public $incrementing = false;
    protected $keyType = 'string';
}
