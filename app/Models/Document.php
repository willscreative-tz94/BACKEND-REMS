<?php

namespace App\Models;

use App\Platform\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A supporting file (title deed, ID, valuation…) attached to a property, customer, agent or deal. */
#[Fillable([
    'organization_id', 'documentable_type', 'documentable_id', 'uploaded_by', 'name', 'category', 'path', 'mime',
    'size', 'verification_status', 'verified_by', 'verified_at', 'rejection_reason', 'expires_at', 'is_private',
])]
class Document extends Model
{
    use BelongsToTenant;

    public const CATEGORIES = ['title_deed', 'id_document', 'tax_certificate', 'sale_agreement', 'lease_agreement', 'valuation_report', 'survey_plan', 'building_permit', 'receipt', 'agent_license', 'other'];

    /** Short names the API accepts for documentable_type. */
    public const OWNERS = [
        'property' => Property::class,
        'customer' => Customer::class,
        'agent' => Agent::class,
        'sale' => Sale::class,
    ];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'expires_at' => 'date', 'is_private' => 'boolean'];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
