<?php

namespace App\Models;

use App\Concerns\VisibleToMember;
use App\Platform\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A booked property visit (in person or virtual). */
#[Fillable([
    'organization_id', 'property_id', 'customer_id', 'agent_id', 'scheduled_at', 'duration_minutes', 'type',
    'status', 'customer_notes', 'agent_notes', 'feedback', 'interest_level',
])]
class Viewing extends Model
{
    use BelongsToTenant, VisibleToMember;

    public const STATUSES = ['requested', 'confirmed', 'completed', 'cancelled', 'no_show'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
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
}
