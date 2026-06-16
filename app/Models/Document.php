<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'user_id',
        'reservation_id',
        'module_slug',
        'module_version',
        'original_filename',
        'mime_type',
        'size_bytes',
        'page_count',
        'temp_path',
        'inputs_json',
        'status',
        'retry_count',
        'last_error',
        'processing_time_ms',
        'organization_id',
        'ai_output_encrypted',
        'reviewed_at',
    ];

    protected $casts = [
        'inputs_json' => 'array',
        'ai_output_encrypted' => 'encrypted',
        'reviewed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function reservation()
    {
        return $this->belongsTo(TokenReservation::class, 'reservation_id');
    }

    public function markFailed(string $reason): void
    {
        $this->update(['status' => 'failed', 'last_error' => $reason]);
    }
}
