<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamDocument extends Model
{
    use HasUuids;
    protected $fillable = ['team_id', 'document_id', 'attached_by_user_id'];
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    public function comments(): HasMany { return $this->hasMany(TeamComment::class); }
}
