<?php

namespace App\Models;

use App\Platform\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A buyer, tenant, investor, seller or landlord in the agency's CRM. */
#[Fillable([
    'organization_id', 'user_id', 'assigned_agent_id', 'name', 'email', 'phone', 'national_id', 'type', 'status',
    'source', 'budget_min', 'budget_max', 'preferred_type', 'preferred_locations', 'preferred_bedrooms',
    'kyc_status', 'notes', 'last_contacted_at',
])]
class Customer extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected function casts(): array
    {
        return [
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'last_contacted_at' => 'datetime',
        ];
    }

    public static function idForUser(?int $userId): ?int
    {
        return $userId ? static::where('user_id', $userId)->value('id') : null;
    }

    /** Agents see the customers assigned to them (brokers: their team's); admins see all. */
    public function scopeVisible(Builder $query): Builder
    {
        $role = tenant()->role();
        if (! $role || in_array($role, ['owner', 'admin'], true)) {
            return $query;
        }
        if ($role === 'customer') {
            return $query->where('user_id', auth()->id());
        }
        $agent = Agent::forUser(auth()->id());

        return $agent
            ? $query->where(fn ($q) => $q->whereIn('assigned_agent_id', $role === 'broker' ? $agent->teamIds() : [$agent->id])->orWhereNull('assigned_agent_id'))
            : $query->whereRaw('1 = 0');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'assigned_agent_id');
    }

    public function viewings(): HasMany
    {
        return $this->hasMany(Viewing::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
