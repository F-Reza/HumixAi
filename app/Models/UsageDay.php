<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageDay extends Model
{
    protected $fillable = ['user_id', 'date', 'used_words', 'reserved_words', 'word_limit'];
    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'used_words' => 'integer', 'reserved_words' => 'integer', 'word_limit' => 'integer'];
    }
}
