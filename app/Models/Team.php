<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasUuids;
    protected $fillable = ['owner_user_id', 'name'];
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_user_id'); }
    public function memberships(): HasMany { return $this->hasMany(TeamMembership::class); }
    public function invitations(): HasMany { return $this->hasMany(TeamInvitation::class); }
    public function documents(): HasMany { return $this->hasMany(TeamDocument::class); }
}
