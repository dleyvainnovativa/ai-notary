<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostalCode extends Model
{
    protected $fillable = ['codigo_postal', 'colonia_code', 'colonia'];
    public $timestamps = false; // import-only data, no need
}
