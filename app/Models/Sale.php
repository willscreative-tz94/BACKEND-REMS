<?php

namespace App\Models;

use App\Concerns\HasReference;
use App\Concerns\VisibleToMember;
use App\Platform\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A deal: an offer on a property that moves to accepted → contract → completed. */
#[Fillable([
    'organization_id', 'reference', 'property_id', 'customer_id', 'agent_id', 'type', 'offer_amount', 'agreed_price',
    'deposit_amount', 'currency', 'status', 'payment_plan', 'offer_date', 'accepted_at', 'closed_at', 'notes',
])]
class Sale extends Model
{
    use BelongsToTenant, HasReference, SoftDeletes, VisibleToMember;

    public const REFERENCE_PREFIX = 'DEAL';

    public const REFERENCE_COLUMN = 'reference';

    public const STATUSES = ['offer', 'negotiating', 'accepted', 'contract', 'completed', 'cancelled', 'rejected'];

    protected $appends = ['amount_paid', 'balance'];

    protected function casts(): array
    {
        return [
            'offer_amount' => 'decimal:2',
            'agreed_price' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'offer_date' => 'date',
            'accepted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function getAmountPaidAttribute(): float
    {
        return (float) $this->payments()->where('status', 'completed')->sum('amount');
    }

    public function getBalanceAttribute(): float
    {
        return max(0, (float) ($this->agreed_price ?? $this->offer_amount) - $this->amount_paid);
    }
}
