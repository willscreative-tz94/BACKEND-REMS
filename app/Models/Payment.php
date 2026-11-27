<?php

namespace App\Models;

use App\Concerns\VisibleToMember;
use App\Platform\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Money received against a deal: booking fee, deposit, instalment, rent. */
#[Fillable([
    'organization_id', 'sale_id', 'customer_id', 'reference', 'amount', 'currency', 'method', 'purpose', 'status',
    'provider', 'provider_reference', 'payer_phone', 'paid_at', 'meta', 'recorded_by',
])]
class Payment extends Model
{
    use BelongsToTenant;
    use VisibleToMember {
        scopeVisible as protected scopeVisibleByOwner;
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'meta' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            $payment->reference ??= 'PAY-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        });
    }

    /** Payments have no agent_id: agents see payments on their deals. */
    public function scopeVisible(Builder $query): Builder
    {
        $role = tenant()->role();
        if (in_array($role, ['agent', 'broker'], true)) {
            $agent = Agent::forUser(auth()->id());
            $ids = $agent ? ($role === 'broker' ? $agent->teamIds() : [$agent->id]) : [0];

            return $query->whereHas('sale', fn ($q) => $q->whereIn('agent_id', $ids));
        }

        return $this->scopeVisibleByOwner($query);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
