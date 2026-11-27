<?php

namespace App\Models;

use App\Platform\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'user_id', 'action', 'subject_type', 'subject_id', 'description', 'properties', 'ip_address'])]
class AuditLog extends Model
{
    use BelongsToTenant;

    public bool $tenantOptional = true;

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
