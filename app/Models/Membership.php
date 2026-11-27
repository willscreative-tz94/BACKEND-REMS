<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** A user's role in one agency: owner, admin, broker, agent or customer. */
#[Fillable(['organization_id', 'user_id', 'role', 'is_active', 'joined_at', 'last_switched_at'])]
class Membership extends Pivot
{
    public const ROLES = ['owner', 'admin', 'broker', 'agent', 'customer'];

    public const STAFF_ROLES = ['owner', 'admin', 'broker', 'agent'];

    protected $table = 'organization_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'joined_at' => 'datetime', 'last_switched_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public function isStaff(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true);
    }
}
