<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotarioProfile extends Model
{
    protected $fillable = ['user_id', 'clave', 'entidad', 'num_notaria', 'nombre_notario', 'entidad_federativa'];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
