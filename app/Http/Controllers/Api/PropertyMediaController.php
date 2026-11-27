<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Photos, videos, virtual tours and floor plans of a listing. */
class PropertyMediaController extends Controller
{
    public function store(Request $request, Property $property): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        $this->ensureCanManage($property->agent_id);

        $max = config('rems.uploads');
        $request->validate([
            'files' => 'required_without:url|array|max:20',
            'files.*' => 'file|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime,application/pdf|max:'.$max['max_video_kb'],
            'url' => 'required_without:files|nullable|url|max:500',
            'type' => 'nullable|in:image,video,virtual_tour,floor_plan',
            'caption' => 'nullable|string|max:190',
        ]);

        $created = [];
        $skipped = [];
        $order = (int) $property->media()->max('sort_order');
        $hasCover = $property->media()->where('is_cover', true)->exists();

        if ($request->filled('url')) {
            $created[] = PropertyMedia::create([
                'property_id' => $property->id, 'type' => $request->input('type', 'video'), 'url' => $request->url,
                'caption' => $request->caption, 'sort_order' => ++$order,
            ]);
        }

        foreach ($request->file('files', []) as $file) {
            $mime = $file->getMimeType();
            $type = $request->input('type') ?: (str_starts_with($mime, 'video/') ? 'video' : (str_contains($mime, 'pdf') ? 'floor_plan' : 'image'));
            if ($type === 'image' && $file->getSize() > $max['max_image_kb'] * 1024) {
                $skipped[] = $file->getClientOriginalName();

                continue;
            }
            $created[] = PropertyMedia::create([
                'property_id' => $property->id,
                'type' => $type,
                'path' => $file->store("properties/{$property->id}", 'public'),
                'caption' => $request->caption,
                'mime' => $mime,
                'size' => $file->getSize(),
                'is_cover' => ! $hasCover && $type === 'image' && ($hasCover = true),
                'sort_order' => ++$order,
            ]);
        }

        if (! $created && $skipped) {
            return response()->json([
                'message' => 'Photos must be '.round($max['max_image_kb'] / 1024).' MB or smaller: '.implode(', ', $skipped),
            ], 422);
        }

        return response()->json(['data' => $created, 'skipped' => $skipped], 201);
    }

    public function update(Request $request, Property $property, PropertyMedia $media): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        $this->ensureCanManage($property->agent_id);
        abort_unless($media->property_id === $property->id, 404);

        $data = $request->validate(['caption' => 'nullable|string|max:190', 'is_cover' => 'sometimes|boolean', 'sort_order' => 'sometimes|integer|min:0']);
        if (! empty($data['is_cover'])) {
            $property->media()->update(['is_cover' => false]);
        }
        $media->update($data);

        return response()->json(['data' => $media]);
    }

    public function destroy(Property $property, PropertyMedia $media): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        $this->ensureCanManage($property->agent_id);
        abort_unless($media->property_id === $property->id, 404);

        if ($media->path) {
            Storage::disk('public')->delete($media->path);
        }
        $media->delete();

        return response()->json(['message' => 'Removed.']);
    }
}
