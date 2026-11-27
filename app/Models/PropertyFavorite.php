<?php

namespace App\Models;

use App\Platform\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'user_id', 'property_id'])]
class PropertyFavorite extends Model
{
    use BelongsToTenant;

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
