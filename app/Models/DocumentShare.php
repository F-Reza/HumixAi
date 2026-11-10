<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentShare extends Model
{
    use HasUuids;
    protected $fillable = ['document_id', 'user_id', 'token_hash', 'snapshot', 'expires_at', 'revoked_at'];
    protected $hidden = ['token_hash'];
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
}
