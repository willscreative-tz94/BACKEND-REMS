<?php

namespace App\Platform\Tenancy\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** where organization_id = current tenant. With no tenant it matches nothing (fail closed). */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = tenant();

        if ($context->isBypassed()) {
            return;
        }

        if (! $context->check()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('organization_id'), $context->id());
    }
}
