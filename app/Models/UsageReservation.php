<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UsageReservation extends Model
{
    use HasUuids;
    protected $fillable = ['user_id', 'usage_day_id', 'words', 'settled_words', 'action', 'reference_id', 'status', 'expires_at'];
    protected function casts(): array
    {
        return ['words' => 'integer', 'settled_words' => 'integer', 'expires_at' => 'datetime'];
    }
}
