<?php

namespace App\Models;

use App\Concerns\HasReference;
use App\Platform\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** A listing: for sale or for rent, with media, location and verification. */
#[Fillable([
    'organization_id', 'agent_id', 'owner_customer_id', 'reference', 'title', 'slug', 'description', 'property_type',
    'listing_type', 'status', 'price', 'currency', 'price_period', 'bedrooms', 'bathrooms', 'parking', 'area_sqm',
    'plot_size_sqm', 'year_built', 'furnished', 'address', 'neighborhood', 'city', 'region', 'country', 'latitude',
    'longitude', 'features', 'amenities', 'is_featured', 'verification_status', 'verified_at', 'verified_by',
    'rejection_reason', 'views_count', 'published_at',
])]
class Property extends Model
{
    use BelongsToTenant, HasReference, SoftDeletes;

    public const REFERENCE_PREFIX = 'PRP';

    public const REFERENCE_COLUMN = 'reference';

    public const TYPES = ['house', 'apartment', 'villa', 'townhouse', 'land', 'commercial', 'office', 'warehouse'];

    public const STATUSES = ['draft', 'available', 'under_offer', 'sold', 'rented', 'off_market'];

    /** Statuses a customer or the public can see. */
    public const PUBLIC_STATUSES = ['available', 'under_offer'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'amenities' => 'array',
            'price' => 'decimal:2',
            'area_sqm' => 'decimal:2',
            'plot_size_sqm' => 'decimal:2',
            'latitude' => 'float',
            'longitude' => 'float',
            'furnished' => 'boolean',
            'is_featured' => 'boolean',
            'verified_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Property $property) {
            if (! $property->slug) {
                $base = Str::slug($property->title) ?: 'property';
                $slug = $base;
                $i = 2;
                while (static::withTrashed()->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $property->slug = $slug;
            }
        });

        static::saving(function (Property $property) {
            if ($property->status === 'available' && ! $property->published_at) {
                $property->published_at = now();
            }
        });
    }

    /** Customers only see published listings. */
    public function scopeVisible(Builder $query): Builder
    {
        return tenant()->role() === 'customer'
            ? $query->whereIn('status', self::PUBLIC_STATUSES)
            : $query;
    }

    public function scopePubliclyListed(Builder $query): Builder
    {
        return $query->whereIn('status', self::PUBLIC_STATUSES);
    }

    /** Haversine distance filter: listings within $km of a point. */
    public function scopeNear(Builder $query, float $lat, float $lng, float $km): Builder
    {
        $haversine = '(6371 * acos(least(1, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))))';

        return $query->whereNotNull('latitude')
            ->selectRaw("properties.*, {$haversine} as distance_km", [$lat, $lng, $lat])
            ->whereRaw("{$haversine} <= ?", [$lat, $lng, $lat, $km])
            ->orderBy('distance_km');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'owner_customer_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(PropertyMedia::class)->orderByDesc('is_cover')->orderBy('sort_order');
    }

    public function cover(): HasOne
    {
        return $this->hasOne(PropertyMedia::class)->where('type', 'image')->orderByDesc('is_cover')->orderBy('sort_order');
    }

    public function viewings(): HasMany
    {
        return $this->hasMany(Viewing::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(PropertyFavorite::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
