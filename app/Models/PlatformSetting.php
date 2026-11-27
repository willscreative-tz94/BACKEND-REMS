<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Key/value settings for the whole platform, edited by super admins. Cached until changed. */
#[Fillable(['key', 'value', 'updated_by'])]
class PlatformSetting extends Model
{
    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** @return array<string, mixed>|null */
    public static function read(string $key): ?array
    {
        return Cache::rememberForever("platform_setting.{$key}", fn () => static::where('key', $key)->value('value'));
    }

    /** @param  array<string, mixed>  $value */
    public static function write(string $key, array $value, ?int $userId = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        Cache::forget("platform_setting.{$key}");
    }
}
