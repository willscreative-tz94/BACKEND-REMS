<?php

namespace App\Concerns;

/**
 * Human-readable per-agency references (PRP-00012, DEAL-00004, CTR-00007).
 * Models set REFERENCE_PREFIX and REFERENCE_COLUMN.
 */
trait HasReference
{
    public static function bootHasReference(): void
    {
        static::creating(function ($model) {
            $column = static::REFERENCE_COLUMN;
            if (! $model->{$column}) {
                $next = (static::withTrashedIfPossible()->max('id') ?? 0) + 1;
                do {
                    $candidate = static::REFERENCE_PREFIX.'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
                    $next++;
                } while (static::withTrashedIfPossible()->where($column, $candidate)->exists());
                $model->{$column} = $candidate;
            }
        });
    }

    protected static function withTrashedIfPossible()
    {
        return method_exists(static::class, 'withTrashed') ? static::withTrashed() : static::query();
    }
}
