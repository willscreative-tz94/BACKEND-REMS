<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\BrandSetting;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Platform Console: every agency on REMS, onboarding and suspension. */
class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orgs = Organization::with(['owner:id,name,email', 'brand'])->withCount('memberships')
            ->when($request->q, fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('name')->get();

        $stats = tenant()->withoutTenancy(fn () => [
            'listings' => Property::selectRaw('organization_id, COUNT(*) c')->groupBy('organization_id')->pluck('c', 'organization_id'),
            'deals' => Sale::where('status', 'completed')->selectRaw('organization_id, COUNT(*) c')->groupBy('organization_id')->pluck('c', 'organization_id'),
        ]);

        return response()->json(['data' => $orgs->map(fn ($o) => [
            ...$o->toArray(),
            'brand' => $o->brandPayload(),
            'listings_count' => (int) ($stats['listings'][$o->id] ?? 0),
            'deals_count' => (int) ($stats['deals'][$o->id] ?? 0),
        ]), 'totals' => [
            'organizations' => $orgs->count(),
            'active' => $orgs->where('status', 'active')->count(),
            'users' => User::count(),
            'listings' => array_sum($stats['listings']->all()),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'slug' => ['nullable', 'alpha_dash', 'max:60', Rule::unique('organizations', 'slug'), Rule::notIn(config('tenancy.reserved_slugs'))],
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:32',
            'city' => 'nullable|string|max:120',
            'country' => 'nullable|string|max:64',
            'currency' => 'nullable|string|size:3',
            'owner_name' => 'required|string|max:120',
            'owner_email' => 'required|email',
            'owner_password' => 'nullable|string|min:8',
            'primary_hex' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_hex' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $org = DB::transaction(function () use ($data) {
            $owner = User::firstOrCreate(['email' => strtolower($data['owner_email'])], [
                'name' => $data['owner_name'], 'password' => $data['owner_password'] ?? 'Password123!',
            ]);
            $org = Organization::create([
                'name' => $data['name'], 'slug' => $data['slug'] ?? Str::slug($data['name']), 'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null, 'city' => $data['city'] ?? null, 'country' => $data['country'] ?? 'Tanzania',
                'currency' => $data['currency'] ?? 'TZS', 'owner_id' => $owner->id, 'status' => 'active', 'verified_at' => now(),
            ]);
            Membership::create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => 'owner', 'is_active' => true, 'joined_at' => now()]);
            BrandSetting::create([
                'organization_id' => $org->id, 'display_name' => $org->name,
                'light_primary_hex' => $data['primary_hex'] ?? '#1e69d2', 'light_accent_hex' => $data['accent_hex'] ?? '#e56c5d',
                'dark_primary_hex' => $data['primary_hex'] ?? '#5f96e3', 'dark_accent_hex' => $data['accent_hex'] ?? '#ef9a8f',
            ]);

            return $org;
        });

        return response()->json(['data' => $org->load('brand')], 201);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate(['status' => 'sometimes|in:active,pending,suspended', 'verified' => 'sometimes|boolean', 'name' => 'sometimes|string|max:120']);
        if (array_key_exists('verified', $data)) {
            $data['verified_at'] = $data['verified'] ? now() : null;
            unset($data['verified']);
        }
        $organization->update($data);

        return response()->json(['data' => $organization]);
    }
}
