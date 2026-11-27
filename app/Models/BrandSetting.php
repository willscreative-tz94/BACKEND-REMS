<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** One per agency: display name, logo and light/dark colour pairs. */
#[Fillable(['organization_id', 'display_name', 'tagline', 'logo_path', 'light_primary_hex', 'light_accent_hex', 'dark_primary_hex', 'dark_accent_hex', 'reply_to_email'])]
class BrandSetting extends Model
{
    protected $appends = ['logo_url'];

    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return str_starts_with($this->logo_path, 'http') || str_starts_with($this->logo_path, '/')
            ? $this->logo_path
            : Storage::disk('public')->url($this->logo_path);
    }
}
