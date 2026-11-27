<?php

namespace App\Models;

use App\Concerns\VisibleToMember;
use App\Platform\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An agent's (or broker's override) earnings on a completed deal. */
#[Fillable([
    'organization_id', 'sale_id', 'agent_id', 'role', 'rate', 'base_amount', 'amount', 'currency', 'status',
    'approved_by', 'approved_at', 'paid_at', 'payment_reference', 'notes',
])]
class Commission extends Model
{
    use BelongsToTenant, VisibleToMember;

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
