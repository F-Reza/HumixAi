<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    protected $attributes = ['plan' => 'free', 'history_enabled' => true];
    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array
    {
        return ['password' => 'hashed', 'email_verified_at' => 'datetime', 'daily_word_limit' => 'integer', 'history_enabled' => 'boolean'];
    }
    public function documents(): HasMany { return $this->hasMany(Document::class); }
    public function apiTokens(): HasMany { return $this->hasMany(ApiToken::class); }
    public function studioJobs(): HasMany { return $this->hasMany(StudioJob::class); }
}
