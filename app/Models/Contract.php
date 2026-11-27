<?php

namespace App\Models;

use App\Concerns\HasReference;
use App\Concerns\VisibleToMember;
use App\Platform\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Sale/lease agreement, listing mandate or offer letter, e-signed by both parties. */
#[Fillable([
    'organization_id', 'number', 'sale_id', 'property_id', 'customer_id', 'agent_id', 'type', 'title', 'body',
    'status', 'value', 'currency', 'start_date', 'end_date', 'customer_signature', 'customer_signed_at',
    'customer_signed_ip', 'agency_signature', 'agency_signed_at', 'sent_at',
])]
class Contract extends Model
{
    use BelongsToTenant, HasReference, SoftDeletes, VisibleToMember;

    public const REFERENCE_PREFIX = 'CTR';

    public const REFERENCE_COLUMN = 'number';

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'customer_signed_at' => 'datetime',
            'agency_signed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
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
