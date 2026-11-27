<?php

namespace App\Platform\Tenancy\Traits;

use App\Models\Organization;
use App\Platform\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Applies TenantScope and stamps organization_id on insert, so a row can
 * never be written without (or into another) tenant.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            $current = tenant()->id();

            if ($model->getAttribute('organization_id') !== null) {
                if ($current && (int) $model->getAttribute('organization_id') !== $current) {
                    throw new LogicException('Refusing to write '.$model::class.' into another organization.');
                }

                return;
            }

            if ($current) {
                $model->setAttribute('organization_id', $current);

                return;
            }

            if (! ($model->tenantOptional ?? false)) {
                throw new LogicException('No current organization while creating '.$model::class.'.');
            }
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('organization_id') && $model->getOriginal('organization_id') !== null) {
                throw new LogicException('organization_id cannot be changed on '.$model::class.'.');
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
