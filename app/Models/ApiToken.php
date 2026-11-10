<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiToken extends Model
{
    use HasUuids;
    protected $fillable = ['user_id', 'name', 'token_hash', 'prefix', 'scopes', 'last_used_at', 'expires_at', 'revoked_at'];
    protected $hidden = ['token_hash'];
    protected function casts(): array
    {
        return ['scopes' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
