<?php

namespace App\Models;

use App\Platform\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A photo, video, virtual tour or floor plan of a property. */
#[Fillable(['organization_id', 'property_id', 'type', 'path', 'url', 'caption', 'mime', 'size', 'is_cover', 'sort_order'])]
class PropertyMedia extends Model
{
    use BelongsToTenant;

    protected $table = 'property_media';

    protected $appends = ['src'];

    protected function casts(): array
    {
        return ['is_cover' => 'boolean'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function getSrcAttribute(): ?string
    {
        return $this->path ? Storage::disk('public')->url($this->path) : $this->url;
    }
}
