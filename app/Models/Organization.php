<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The tenant: a real-estate agency. Platform table, never tenant-scoped. */
#[Fillable([
    'name', 'slug', 'email', 'phone', 'address', 'city', 'country', 'currency', 'timezone', 'license_number',
    'status', 'verified_at', 'default_commission_rate', 'settings', 'owner_id',
])]
class Organization extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'verified_at' => 'datetime',
            'default_commission_rate' => 'decimal:2',
        ];
    }

    public static function findByIdentifier(string $identifier): ?self
    {
        return ctype_digit($identifier)
            ? static::find((int) $identifier)
            : static::where('slug', strtolower($identifier))->first();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->using(Membership::class)
            ->withPivot(['id', 'role', 'is_active', 'joined_at'])
            ->withTimestamps();
    }

    public function brand(): HasOne
    {
        return $this->hasOne(BrandSetting::class);
    }

    /** Brand with defaults, for API payloads. */
    public function brandPayload(): array
    {
        $brand = $this->brand ?? new BrandSetting;

        return [
            'display_name' => $brand->display_name ?: $this->name,
            'tagline' => $brand->tagline,
            'logo_url' => $brand->logo_url,
            'light_primary_hex' => $brand->light_primary_hex ?? '#1e69d2',
            'light_accent_hex' => $brand->light_accent_hex ?? '#e56c5d',
            'dark_primary_hex' => $brand->dark_primary_hex ?? '#5f96e3',
            'dark_accent_hex' => $brand->dark_accent_hex ?? '#ef9a8f',
        ];
    }
}
