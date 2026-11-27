<?php

namespace App\Mail;

use App\Models\Organization;

/**
 * Colours, name and logo an email is dressed in: the current agency's brand
 * when there is one, else the REMS platform's. Captured when the mailable is
 * built, so it survives being rendered outside the tenant context.
 */
final readonly class EmailBrand
{
    /** The willsrems mark, served from public/. */
    public const PLATFORM_LOGO = '/willsrems-logo.png';

    public function __construct(
        public string $name,
        public ?string $tagline,
        public ?string $logoUrl,
        public string $primary,
        public string $accent,
        public ?string $replyTo,
        public string $platformName,
        public string $poweredBy,
    ) {}

    public static function current(?Organization $organization = null): self
    {
        $organization ??= tenant()->organization();
        $platform = (string) config('app.name', 'willsrems');
        $poweredBy = (string) config('app.powered_by', 'willscreativedesign');

        if (! $organization) {
            return new self($platform, 'Real Estate Management System', self::absolute(self::PLATFORM_LOGO), '#1e69d2', '#e56c5d', null, $platform, $poweredBy);
        }

        $organization->loadMissing('brand');
        $brand = $organization->brandPayload();
        $logo = $brand['logo_url'] ?? null;

        return new self(
            name: $brand['display_name'],
            tagline: $brand['tagline'] ?: 'Real estate agency',
            // Mail clients need an absolute URL; SVGs (blocked by Gmail/Outlook) fall back to a monogram.
            logoUrl: $logo && ! str_ends_with(strtolower($logo), '.svg') ? self::absolute($logo) : null,
            primary: self::hex($brand['light_primary_hex'], '#1e69d2'),
            accent: self::hex($brand['light_accent_hex'], '#e56c5d'),
            replyTo: $organization->brand?->reply_to_email ?: $organization->email,
            platformName: $platform,
            poweredBy: $poweredBy,
        );
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];

        return strtoupper(mb_substr($words[0] ?? 'R', 0, 1).mb_substr($words[1] ?? '', 0, 1));
    }

    /** A pale tint of the primary colour for panels and badges (mail clients don't support color-mix). */
    public function tint(float $amount = 0.9): string
    {
        [$r, $g, $b] = sscanf($this->primary, '#%02x%02x%02x');

        return sprintf('#%02x%02x%02x', ...array_map(fn ($c) => (int) round($c + (255 - $c) * $amount), [$r, $g, $b]));
    }

    private static function absolute(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    private static function hex(?string $value, string $fallback): string
    {
        return $value && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
    }
}
