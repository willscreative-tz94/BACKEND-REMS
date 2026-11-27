<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\SplashSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Platform Console: the splash (opening) screen video, poster and copy. Super admins only. */
class SplashController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => SplashSettings::adminPayload()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm', 'max:'.config('rems.uploads.max_video_kb')],
            'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('rems.uploads.max_image_kb')],
            'video_enabled' => ['sometimes', 'boolean'],
            'overlay' => ['sometimes', 'integer', 'between:0,90'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:120'],
            'lede' => ['sometimes', 'nullable', 'string', 'max:280'],
        ]);

        $changes = collect($data)->only(['video_enabled', 'overlay', 'headline', 'lede'])
            ->map(fn ($value, $key) => match ($key) {
                'video_enabled' => (bool) $value,
                'overlay' => (int) $value,
                default => filled($value) ? trim($value) : null,
            })->all();

        return response()->json([
            'data' => SplashSettings::update($changes, $request->file('video'), $request->file('poster'), $request->user()),
            'message' => 'Splash screen updated.',
        ]);
    }

    public function reset(Request $request, string $asset): JsonResponse
    {
        return response()->json([
            'data' => SplashSettings::reset($asset, $request->user()),
            'message' => $asset === 'video' ? 'Splash video reset to the willsrems default.' : 'Poster reset to the willsrems default.',
        ]);
    }
}
