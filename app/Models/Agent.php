<?php

namespace App\Models;

use App\Platform\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An agent or broker profile inside an agency. Brokers lead a team of agents. */
#[Fillable([
    'organization_id', 'user_id', 'broker_id', 'type', 'license_number', 'license_expiry', 'specialization', 'bio',
    'languages', 'service_areas', 'commission_rate', 'broker_split', 'verification_status', 'verified_at',
    'verified_by', 'rejection_reason', 'rating',
])]
class Agent extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected function casts(): array
    {
        return [
            'languages' => 'array',
            'service_areas' => 'array',
            'license_expiry' => 'date',
            'verified_at' => 'datetime',
            'commission_rate' => 'decimal:2',
            'broker_split' => 'decimal:2',
            'rating' => 'decimal:2',
        ];
    }

    public static function forUser(?int $userId): ?self
    {
        return $userId ? static::where('user_id', $userId)->first() : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(self::class, 'broker_id');
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(self::class, 'broker_id');
    }

    /** @return list<int> this agent plus, for a broker, everyone on the team */
    public function teamIds(): array
    {
        return array_values(array_unique([$this->id, ...$this->teamMembers()->pluck('id')->all()]));
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'assigned_agent_id');
    }

    public function viewings(): HasMany
    {
        return $this->hasMany(Viewing::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }
}
