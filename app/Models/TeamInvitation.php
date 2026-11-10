<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamInvitation extends Model
{
    use HasUuids;
    protected $fillable = ['team_id', 'invited_by_user_id', 'email', 'role', 'token_hash', 'expires_at', 'revoked_at', 'accepted_by_user_id', 'accepted_at'];
    protected $hidden = ['token_hash'];
    protected function casts(): array { return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'accepted_at' => 'datetime']; }
    public function team(): BelongsTo { return $this->belongsTo(Team::class); }
}
