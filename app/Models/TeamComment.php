<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamComment extends Model
{
    use HasUuids;
    protected $fillable = ['team_document_id', 'user_id', 'body'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
