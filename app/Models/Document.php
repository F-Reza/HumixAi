<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasUuids;
    protected $fillable = ['user_id', 'title', 'source_text', 'current_text', 'settings'];
    protected function casts(): array { return ['settings' => 'array']; }
    public function getSettingsAttribute(mixed $value): array
    {
        return self::safeSettings(is_array($value) ? $value : (json_decode($value ?? '[]', true) ?: []));
    }
    public function setSettingsAttribute(?array $value): void
    {
        $this->attributes['settings'] = json_encode(self::safeSettings($value ?? []), JSON_THROW_ON_ERROR);
    }
    public static function safeSettings(array $settings): array
    {
        return array_intersect_key($settings, array_flip(['mode', 'language', 'strength', 'length', 'protected_terms', 'selection']));
    }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function revisions(): HasMany { return $this->hasMany(DocumentRevision::class); }
    public function shares(): HasMany { return $this->hasMany(DocumentShare::class); }
}
