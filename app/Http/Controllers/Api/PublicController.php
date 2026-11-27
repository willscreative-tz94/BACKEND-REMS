<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Property;
use App\Services\NotificationService;
use App\Services\SplashSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** No sign-in needed: agencies, their published listings, enquiries and branding. */
class PublicController extends Controller
{
    /**
     * How this install runs, read by the SPA before it boots: marketplace
     * (multitenant) or a single agency's own system (enterprise).
     */
    public function config(): JsonResponse
    {
        $enterprise = self::enterpriseOrganization();

        return response()->json([
            'mode' => $enterprise ? 'enterprise' : 'multitenant',
            'base_domain' => (string) config('tenancy.base_domain'),
            'platform' => ['name' => config('app.name'), 'tagline' => 'Real Estate Management System', 'powered_by' => config('app.powered_by')],
            'splash' => SplashSettings::publicPayload(),
            'organization' => $enterprise ? [
                'name' => $enterprise->name, 'slug' => $enterprise->slug, 'city' => $enterprise->city,
                'country' => $enterprise->country, 'verified' => $enterprise->verified_at !== null,
                'brand' => $enterprise->brandPayload(),
            ] : null,
        ]);
    }

    /** The pinned agency in enterprise mode; null in multitenant mode or if it is missing/inactive. */
    public static function enterpriseOrganization(): ?Organization
    {
        $slug = config('tenancy.enterprise_organization');
        if (config('tenancy.mode') !== 'enterprise' || ! $slug) {
            return null;
        }

        return Organization::with('brand')->where('slug', $slug)->where('status', 'active')->first();
    }

    public function organizations(): JsonResponse
    {
        $orgs = Organization::with('brand')->where('status', 'active')
            ->when(config('tenancy.mode') === 'enterprise', fn ($q) => $q->where('slug', config('tenancy.enterprise_organization')))
            ->orderBy('name')->get()
            ->map(fn (Organization $o) => [
                'name' => $o->name, 'slug' => $o->slug, 'city' => $o->city, 'country' => $o->country,
                'verified' => $o->verified_at !== null, 'brand' => $o->brandPayload(),
                'listings' => tenant()->run($o, fn () => Property::publiclyListed()->count()),
            ]);

        return response()->json(['data' => $orgs]);
    }

    public function brand(): JsonResponse
    {
        $org = tenant()->organization();

        return response()->json($org ? $org->brandPayload() : [
            'display_name' => config('app.name'), 'tagline' => 'Real Estate Management System', 'logo_url' => null,
            'light_primary_hex' => '#1e69d2', 'light_accent_hex' => '#e56c5d', 'dark_primary_hex' => '#5f96e3', 'dark_accent_hex' => '#ef9a8f',
        ]);
    }

    public function properties(Request $request): JsonResponse
    {
        if (! tenant()->check()) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = Property::publiclyListed()->with(['cover', 'agent.user:id,name,phone,avatar_path'])
            ->when($request->listing_type, fn ($q, $v) => $q->where('listing_type', $v))
            ->when($request->property_type, fn ($q, $v) => $q->where('property_type', $v))
            ->when($request->city, fn ($q, $v) => $q->where('city', $v))
            ->when($request->min_price, fn ($q, $v) => $q->where('price', '>=', $v))
            ->when($request->max_price, fn ($q, $v) => $q->where('price', '<=', $v))
            ->when($request->bedrooms, fn ($q, $v) => $q->where('bedrooms', '>=', $v))
            ->when($request->featured, fn ($q) => $q->where('is_featured', true))
            ->when($request->q, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'like', "%{$v}%")->orWhere('neighborhood', 'like', "%{$v}%")->orWhere('city', 'like', "%{$v}%")))
            ->orderByDesc('is_featured')->latest('published_at');

        return response()->json($query->paginate(min(48, (int) $request->input('per_page', 12))));
    }

    public function property(string $slug): JsonResponse
    {
        $property = Property::publiclyListed()->with(['media', 'agent.user:id,name,email,phone,avatar_path'])
            ->where('slug', $slug)->orWhere('reference', $slug)->firstOrFail();
        $property->increment('views_count');

        return response()->json(['data' => $property]);
    }

    /** "Contact agent" from the public site: creates/updates a lead and opens a conversation. */
    public function enquiry(Request $request, Property $property): JsonResponse
    {
        abort_unless(in_array($property->status, Property::PUBLIC_STATUSES, true), 404);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'phone' => 'nullable|string|max:32',
            'message' => 'required|string|max:2000',
        ]);

        $customer = Customer::firstOrCreate(
            ['email' => strtolower($data['email'])],
            ['name' => $data['name'], 'phone' => $data['phone'] ?? null, 'type' => $property->listing_type === 'rent' ? 'tenant' : 'buyer',
                'status' => 'lead', 'source' => 'website', 'assigned_agent_id' => $property->agent_id],
        );

        $conversation = Conversation::create([
            'property_id' => $property->id, 'customer_id' => $customer->id, 'agent_id' => $property->agent_id,
            'subject' => "Enquiry: {$property->title}", 'last_message_at' => now(),
        ]);
        Message::create(['conversation_id' => $conversation->id, 'sender_id' => $customer->user_id, 'body' => $data['message']]);

        $property->agent
            ? NotificationService::toAgent($property->agent, 'enquiry.received', "New enquiry on {$property->reference}", "{$customer->name}: ".str($data['message'])->limit(120), "/messages/{$conversation->id}")
            : NotificationService::toAdmins('enquiry.received', "New enquiry on {$property->reference}", "{$customer->name}: ".str($data['message'])->limit(120), "/messages/{$conversation->id}");

        return response()->json(['message' => 'Thank you — an agent will contact you shortly.'], 201);
    }
}
