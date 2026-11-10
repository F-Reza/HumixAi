<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudioJob extends Model
{
    use HasUuids;
    protected $fillable = ['user_id', 'document_id', 'reservation_id', 'idempotency_key', 'request_hash', 'action', 'payload', 'result', 'status', 'stage', 'error', 'started_at', 'completed_at'];
    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
}
