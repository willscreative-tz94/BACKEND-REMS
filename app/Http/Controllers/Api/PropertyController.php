<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyFavorite;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PropertyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Property::visible()
            ->with(['cover', 'agent.user:id,name,avatar_path'])
            ->withCount(['viewings', 'media'])
            ->when($request->q, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'like', "%{$v}%")
                ->orWhere('reference', 'like', "%{$v}%")->orWhere('neighborhood', 'like', "%{$v}%")->orWhere('city', 'like', "%{$v}%")))
            ->when($request->status, fn ($q, $v) => $q->whereIn('status', (array) explode(',', $v)))
            ->when($request->listing_type, fn ($q, $v) => $q->where('listing_type', $v))
            ->when($request->property_type, fn ($q, $v) => $q->where('property_type', $v))
            ->when($request->city, fn ($q, $v) => $q->where('city', $v))
            ->when($request->agent_id, fn ($q, $v) => $q->where('agent_id', $v))
            ->when($request->verification_status, fn ($q, $v) => $q->where('verification_status', $v))
            ->when($request->min_price, fn ($q, $v) => $q->where('price', '>=', $v))
            ->when($request->max_price, fn ($q, $v) => $q->where('price', '<=', $v))
            ->when($request->bedrooms, fn ($q, $v) => $q->where('bedrooms', '>=', $v))
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true))
            ->when($request->boolean('mine') && $this->currentAgent(), fn ($q) => $q->where('agent_id', $this->currentAgent()->id))
            // Map viewport: north/south/east/west bounds.
            ->when($request->filled(['north', 'south', 'east', 'west']), fn ($q) => $q
                ->whereBetween('latitude', [(float) $request->south, (float) $request->north])
                ->whereBetween('longitude', [(float) $request->west, (float) $request->east]));

        if ($request->filled(['lat', 'lng'])) {
            $query->near((float) $request->lat, (float) $request->lng, (float) $request->input('radius_km', 10));
        } else {
            match ($request->input('sort', 'newest')) {
                'price_asc' => $query->orderBy('price'),
                'price_desc' => $query->orderByDesc('price'),
                'popular' => $query->orderByDesc('views_count'),
                default => $query->latest(),
            };
        }

        $page = $query->paginate($this->perPage());
        $favorites = PropertyFavorite::where('user_id', auth()->id())->pluck('property_id')->all();
        $page->getCollection()->each(fn ($p) => $p->setAttribute('is_favorite', in_array($p->id, $favorites, true)));

        return response()->json($page);
    }

    /** Lightweight points for the map view (every visible listing with coordinates). */
    public function mapPoints(Request $request): JsonResponse
    {
        $points = Property::visible()->with('cover')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->when($request->listing_type, fn ($q, $v) => $q->where('listing_type', $v))
            ->when($request->property_type, fn ($q, $v) => $q->where('property_type', $v))
            ->when($request->status, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->limit(1000)->get()
            ->map(fn (Property $p) => [
                'id' => $p->id, 'title' => $p->title, 'reference' => $p->reference, 'price' => $p->price, 'currency' => $p->currency,
                'price_period' => $p->price_period, 'listing_type' => $p->listing_type, 'property_type' => $p->property_type,
                'status' => $p->status, 'bedrooms' => $p->bedrooms, 'bathrooms' => $p->bathrooms, 'city' => $p->city,
                'neighborhood' => $p->neighborhood, 'latitude' => $p->latitude, 'longitude' => $p->longitude, 'cover' => $p->cover?->src,
            ]);

        return response()->json(['data' => $points]);
    }

    public function show(Property $property): JsonResponse
    {
        abort_if($this->isCustomer() && ! in_array($property->status, Property::PUBLIC_STATUSES, true), 404);

        $property->load(['media', 'agent.user:id,name,email,phone,avatar_path', 'owner:id,name,phone,email'])
            ->loadCount(['viewings', 'favorites']);
        if ($this->isCustomer()) {
            $property->increment('views_count');
            $property->unsetRelation('owner');
        }
        if ($this->isStaff()) {
            $property->load(['documents.uploader:id,name', 'sales' => fn ($q) => $q->with('customer:id,name')->latest()->limit(10)]);
        }
        $property->setAttribute('is_favorite', PropertyFavorite::where('user_id', auth()->id())->where('property_id', $property->id)->exists());

        return response()->json(['data' => $property]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        $data = $this->validated($request);
        if (! $this->isAdmin() || empty($data['agent_id'])) {
            $data['agent_id'] = $data['agent_id'] ?? $this->currentAgent()?->id;
        }
        $data['currency'] ??= tenant()->organization()->currency;

        $property = Property::create($data);
        AuditLogger::log('property.created', $property, "Listed {$property->reference} — {$property->title}");

        return response()->json(['data' => $property->load('media')], 201);
    }

    public function update(Request $request, Property $property): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        $this->ensureCanManage($property->agent_id);
        $data = $this->validated($request, $property);

        // Material changes on a verified listing send it back for verification.
        if ($property->verification_status === 'verified' && array_intersect_key($data, array_flip(['price', 'title', 'address', 'latitude', 'longitude'])) && ! $this->isAdmin()) {
            foreach (['price', 'title', 'address'] as $key) {
                if (array_key_exists($key, $data) && (string) $data[$key] !== (string) $property->{$key}) {
                    $data['verification_status'] = 'pending';
                    break;
                }
            }
        }

        $before = $property->status;
        $property->update($data);
        AuditLogger::log('property.updated', $property, "Updated {$property->reference}", ['changes' => array_keys($property->getChanges())]);

        if ($before !== $property->status) {
            $property->favorites()->with('property')->get()->each(fn ($fav) => NotificationService::send(
                \App\Models\User::find($fav->user_id), 'property.status', "{$property->title} is now ".str_replace('_', ' ', $property->status), null, "/properties/{$property->id}"
            ));
        }

        return response()->json(['data' => $property->fresh(['media', 'agent.user'])]);
    }

    public function destroy(Property $property): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker');
        $property->delete();
        AuditLogger::log('property.deleted', $property, "Archived {$property->reference}");

        return response()->json(['message' => 'Property archived.']);
    }

    public function submitForVerification(Property $property): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        $this->ensureCanManage($property->agent_id);
        $property->update(['verification_status' => 'pending', 'rejection_reason' => null]);
        NotificationService::toAdmins('verification.requested', "Verify listing {$property->reference}", $property->title, '/admin/verifications');

        return response()->json(['data' => $property]);
    }

    public function toggleFavorite(Property $property): JsonResponse
    {
        $existing = PropertyFavorite::where('user_id', auth()->id())->where('property_id', $property->id)->first();
        $existing ? $existing->delete() : PropertyFavorite::create(['user_id' => auth()->id(), 'property_id' => $property->id]);

        return response()->json(['is_favorite' => ! $existing]);
    }

    public function favorites(): JsonResponse
    {
        $ids = PropertyFavorite::where('user_id', auth()->id())->pluck('property_id');
        $properties = Property::visible()->with('cover')->whereIn('id', $ids)->get()->each->setAttribute('is_favorite', true);

        return response()->json(['data' => $properties]);
    }

    /** Distinct cities, for filters. */
    public function cities(): JsonResponse
    {
        return response()->json(['data' => Property::visible()->whereNotNull('city')->distinct()->orderBy('city')->pluck('city')]);
    }

    private function validated(Request $request, ?Property $property = null): array
    {
        $rule = $property ? 'sometimes' : 'required';

        $data = $request->validate([
            'title' => "{$rule}|string|max:190",
            'description' => 'nullable|string|max:20000',
            'property_type' => [$rule, Rule::in(Property::TYPES)],
            'listing_type' => [$rule, Rule::in(['sale', 'rent'])],
            'status' => ['sometimes', Rule::in(Property::STATUSES)],
            'price' => "{$rule}|numeric|min:0",
            'currency' => 'sometimes|string|size:3',
            'price_period' => ['sometimes', Rule::in(['once', 'month', 'year'])],
            'bedrooms' => 'nullable|integer|min:0|max:50',
            'bathrooms' => 'nullable|integer|min:0|max:50',
            'parking' => 'nullable|integer|min:0|max:100',
            'area_sqm' => 'nullable|numeric|min:0',
            'plot_size_sqm' => 'nullable|numeric|min:0',
            'year_built' => 'nullable|integer|min:1800|max:'.(date('Y') + 5),
            'furnished' => 'sometimes|boolean',
            'address' => 'nullable|string|max:255',
            'neighborhood' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'region' => 'nullable|string|max:120',
            'country' => 'sometimes|string|max:64',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'features' => 'nullable|array',
            'features.*' => 'string|max:80',
            'amenities' => 'nullable|array',
            'amenities.*' => 'string|max:80',
            'is_featured' => 'sometimes|boolean',
            'agent_id' => ['nullable', Rule::exists('agents', 'id')->where('organization_id', tenant()->id())],
            'owner_customer_id' => ['nullable', Rule::exists('customers', 'id')->where('organization_id', tenant()->id())],
        ]);

        if (! $this->isAdmin()) {
            unset($data['is_featured']);
            if ($this->role() === 'agent') {
                unset($data['agent_id']);
            }
        }

        return $data;
    }
}
