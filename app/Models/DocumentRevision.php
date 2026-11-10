<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRevision extends Model
{
    use HasUuids;
    protected $fillable = ['document_id', 'action', 'input_text', 'output_text', 'result', 'text_hash'];
    protected function casts(): array { return ['result' => 'array']; }
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
}
