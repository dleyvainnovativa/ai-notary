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
        'review_data_encrypted',
        'review_saved_at',
        'review_version',
    ];

    protected $casts = [
        'inputs_json' => 'array',
        'ai_output_encrypted' => 'encrypted',
        'reviewed_at' => 'datetime',
        'review_data_encrypted' => 'encrypted:array',
        'review_saved_at' => 'datetime',
        'review_version' => 'integer',
    ];

    /** Statuses whose review form can be opened (and whose draft can be saved). */
    public const REVIEWABLE = ['requires_review', 'completed'];

    public function isReviewable(): bool
    {
        return in_array($this->status, self::REVIEWABLE, true)
            && ($this->review_data_encrypted !== null || $this->ai_output_encrypted !== null);
    }

    /**
     * Persist the user's form data as the current draft. Increments the version
     * used for optimistic locking (two tabs editing the same document).
     */
    public function saveDraft(array $data): void
    {
        $this->forceFill([
            'review_data_encrypted' => $data,
            'review_saved_at' => now(),
            'review_version' => (int) $this->review_version + 1,
        ])->save();
    }

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
