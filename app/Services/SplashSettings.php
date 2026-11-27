<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The opening (splash) screen, edited by super admins in the Platform
 * Console: the background video and poster, how dark the overlay is, and an
 * optional headline for visitors. Anything left unset falls back to the
 * REMS defaults bundled with the SPA.
 */
class SplashSettings
{
    public const KEY = 'splash';

    private const DISK = 'public';

    private const DIR = 'platform/splash';

    /** @var array{video_path: ?string, video_mime: ?string, poster_path: ?string, video_enabled: bool, overlay: int, headline: ?string, lede: ?string} */
    private const DEFAULTS = [
        'video_path' => null,
        'video_mime' => null,
        'poster_path' => null,
        'video_enabled' => true,
        'overlay' => 55,
        'headline' => null,
        'lede' => null,
    ];

    /** @return array<string, mixed> */
    public static function raw(): array
    {
        return array_merge(self::DEFAULTS, PlatformSetting::read(self::KEY) ?? []);
    }

    /**
     * What the SPA needs (part of GET /api/app-config). Null URLs mean "use the bundled default".
     *
     * @return array{video_url: ?string, video_type: ?string, poster_url: ?string, video_enabled: bool, overlay: int, headline: ?string, lede: ?string, version: ?string}
     */
    public static function publicPayload(): array
    {
        $s = self::raw();

        return [
            'video_url' => self::url($s['video_path']),
            'video_type' => $s['video_mime'],
            'poster_url' => self::url($s['poster_path']),
            'video_enabled' => (bool) $s['video_enabled'],
            'overlay' => (int) $s['overlay'],
            'headline' => $s['headline'],
            'lede' => $s['lede'],
            'version' => $s['updated_at'] ?? null,
        ];
    }

    /** Console view: the public payload plus who last changed it. */
    public static function adminPayload(): array
    {
        $s = self::raw();

        return [
            ...self::publicPayload(),
            'is_default_video' => $s['video_path'] === null,
            'is_default_poster' => $s['poster_path'] === null,
            'updated_by' => $s['updated_by_name'] ?? null,
            'max_video_mb' => (int) floor(config('rems.uploads.max_video_kb') / 1024),
        ];
    }

    /** @param  array{video_enabled?: bool, overlay?: int, headline?: ?string, lede?: ?string}  $changes */
    public static function update(array $changes, ?UploadedFile $video, ?UploadedFile $poster, User $by): array
    {
        $s = self::raw();

        if ($video) {
            self::delete($s['video_path']);
            $s['video_path'] = $video->store(self::DIR, self::DISK);
            $s['video_mime'] = $video->getMimeType() === 'video/webm' ? 'video/webm' : 'video/mp4';
        }
        if ($poster) {
            self::delete($s['poster_path']);
            $s['poster_path'] = $poster->store(self::DIR, self::DISK);
        }

        return self::save(array_merge($s, $changes), $by);
    }

    /** Back to the bundled REMS default for one asset. */
    public static function reset(string $asset, User $by): array
    {
        $s = self::raw();
        self::delete($s["{$asset}_path"]);
        $s["{$asset}_path"] = null;
        if ($asset === 'video') {
            $s['video_mime'] = null;
        }

        return self::save($s, $by);
    }

    private static function save(array $settings, User $by): array
    {
        $settings['updated_at'] = now()->toIso8601String();
        $settings['updated_by_name'] = $by->name;
        PlatformSetting::write(self::KEY, $settings, $by->id);

        return self::adminPayload();
    }

    private static function url(?string $path): ?string
    {
        return $path ? Storage::disk(self::DISK)->url($path) : null;
    }

    private static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
