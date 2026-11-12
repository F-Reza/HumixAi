<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable implements \Illuminate\Contracts\Auth\MustVerifyEmail, \Illuminate\Contracts\Translation\HasLocalePreference
{
    use \Illuminate\Notifications\Notifiable;
    protected $attributes = ['plan' => 'free', 'history_enabled' => true];
    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array
    {
        return ['suspended_at' => 'datetime', 'quota_override' => 'integer', 'password' => 'hashed', 'email_verified_at' => 'datetime', 'daily_word_limit' => 'integer', 'history_enabled' => 'boolean'];
    }
    public function preferredLocale(): string { return \App\Support\Locales::normalize($this->locale); }
    public function sendPasswordResetNotification($token): void { $this->notify(new \App\Notifications\ResetAccountPassword($token)); }
    public function sendEmailVerificationNotification(): void { $this->notify(new \App\Notifications\VerifyAccountEmail()); }
    public function documents(): HasMany { return $this->hasMany(Document::class); }
    public function apiTokens(): HasMany { return $this->hasMany(ApiToken::class); }
    public function studioJobs(): HasMany { return $this->hasMany(StudioJob::class); }
}
