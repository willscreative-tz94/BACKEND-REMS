<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AppNotification;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserOtp;
use App\Services\NotificationService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'This account has been deactivated.']);
        }

        if (OtpService::enabled()) {
            // Dress the code email in the agency the user last worked in, if any.
            $organization = $user->memberships()->where('is_active', true)->orderByDesc('last_switched_at')->with('organization')->first()?->organization;
            $issue = fn () => $this->otp->issue($user, UserOtp::PURPOSE_LOGIN);

            return response()->json($organization ? tenant()->run($organization, $issue) : $issue());
        }

        return response()->json($this->completeLogin($user));
    }

    /** Second step: the emailed one-time code. Issues the API token on success. */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => 'required|string|size:64', 'code' => 'required|string|max:16']);
        $otp = $this->otp->verify($data['challenge'], $data['code']);
        $user = $otp->user;

        // Either purpose proves the user reads this mailbox.
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $preferred = $otp->purpose === UserOtp::PURPOSE_VERIFY_EMAIL
            ? $user->memberships()->latest('id')->with('organization')->first()?->organization?->slug
            : null;

        return response()->json($this->completeLogin($user, $preferred));
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => 'required|string|size:64']);

        return response()->json($this->otp->resend($data['challenge']));
    }

    private function completeLogin(User $user, ?string $preferredSlug = null): array
    {
        $user->forceFill(['last_login_at' => now()])->save();

        return $this->session($user, $preferredSlug);
    }

    /** Buyer/tenant self-registration into an agency (joins as customer, creates the CRM record). */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'phone' => 'nullable|string|max:32',
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'organization' => 'required|string|exists:organizations,slug',
            'type' => 'nullable|in:buyer,tenant,investor,seller,landlord',
        ]);

        $organization = Organization::where('slug', $data['organization'])->where('status', 'active')->firstOrFail();

        $user = DB::transaction(function () use ($data, $organization) {
            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);

            Membership::create([
                'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'customer', 'is_active' => true, 'joined_at' => now(),
            ]);

            tenant()->run($organization, function () use ($user, $data) {
                $existing = Customer::where('email', $user->email)->whereNull('user_id')->first();
                $existing
                    ? $existing->update(['user_id' => $user->id, 'status' => 'active'])
                    : Customer::create([
                        'user_id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
                        'type' => $data['type'] ?? 'buyer', 'status' => 'active', 'source' => 'website',
                    ]);
                NotificationService::toAdmins('customer.registered', 'New customer registered', "{$user->name} ({$user->email}) signed up on the portal.", '/customers');
            });

            return $user;
        });

        if (OtpService::enabled()) {
            return response()->json(tenant()->run($organization, fn () => $this->otp->issue($user, UserOtp::PURPOSE_VERIFY_EMAIL)), 201);
        }

        return response()->json($this->completeLogin($user, $organization->slug), 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /** The user as seen inside the current agency, with all their agencies (the switcher). */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $this->userPayload($user),
            'organizations' => $this->organizationsFor($user),
            'organization' => $this->currentOrganizationPayload(),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'phone' => 'nullable|string|max:32',
            'avatar' => 'nullable|image|max:4096',
            'current_password' => 'required_with:password|current_password',
            'password' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $user->avatar_path = $request->file('avatar')->store('avatars', 'public');
        }

        $user->fill(collect($data)->only(['name', 'phone'])->all());
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        return response()->json(['data' => $this->userPayload($user->fresh())]);
    }

    public function switchOrganization(Request $request, string $slug): JsonResponse
    {
        $organization = Organization::where('slug', $slug)->firstOrFail();
        $membership = $organization->memberships()->where('user_id', $request->user()->id)->where('is_active', true)->first();
        abort_unless($membership || $request->user()->isPlatformOwner(), 403, 'You are not a member of this agency.');

        $membership?->update(['last_switched_at' => now()]);

        return response()->json(['message' => 'Switched.', 'slug' => $organization->slug]);
    }

    private function session(User $user, ?string $preferredSlug = null): array
    {
        $token = $user->createToken('rems-spa')->plainTextToken;
        $organizations = $this->organizationsFor($user);
        $default = $preferredSlug
            ?? $user->memberships()->where('is_active', true)->orderByDesc('last_switched_at')->with('organization')->first()?->organization?->slug
            ?? ($organizations[0]['slug'] ?? null);

        return [
            'token' => $token,
            'user' => $this->userPayload($user),
            'organizations' => $organizations,
            'default_organization' => $default,
        ];
    }

    private function userPayload(User $user): array
    {
        $membership = tenant()->membership();
        $agent = tenant()->check() ? Agent::forUser($user->id) : null;
        $customer = tenant()->check() ? Customer::where('user_id', $user->id)->first() : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_url,
            'platform_role' => $user->platform_role,
            'role' => $membership?->role ?? ($user->isPlatformOwner() && tenant()->check() ? 'admin' : null),
            'is_admin' => $membership?->isAdmin() || ($user->isPlatformOwner() && tenant()->check()),
            'agent_id' => $agent?->id,
            'agent_type' => $agent?->type,
            'customer_id' => $customer?->id,
            'last_login_at' => $user->last_login_at,
        ];
    }

    private function organizationsFor(User $user): array
    {
        $memberships = $user->memberships()->where('is_active', true)->with('organization.brand')->get()
            ->filter(fn ($m) => $m->organization);

        $list = $memberships->map(fn (Membership $m) => [
            'id' => $m->organization->id,
            'name' => $m->organization->name,
            'slug' => $m->organization->slug,
            'status' => $m->organization->status,
            'role' => $m->role,
            'logo_url' => $m->organization->brand?->logo_url,
            'unread_notifications' => AppNotification::withoutGlobalScopes()
                ->where('organization_id', $m->organization_id)->where('user_id', $user->id)->whereNull('read_at')->count(),
        ])->values()->all();

        // The platform owner can open every agency from the switcher.
        if ($user->isPlatformOwner()) {
            $mine = array_column($list, 'slug');
            Organization::with('brand')->whereNotIn('slug', $mine)->orderBy('name')->get()->each(function ($org) use (&$list) {
                $list[] = ['id' => $org->id, 'name' => $org->name, 'slug' => $org->slug, 'status' => $org->status,
                    'role' => 'platform', 'logo_url' => $org->brand?->logo_url, 'unread_notifications' => 0];
            });
        }

        return $list;
    }

    private function currentOrganizationPayload(): ?array
    {
        $org = tenant()->organization();
        if (! $org) {
            return null;
        }
        $org->loadMissing('brand');

        return [
            'id' => $org->id,
            'name' => $org->name,
            'slug' => $org->slug,
            'email' => $org->email,
            'phone' => $org->phone,
            'city' => $org->city,
            'country' => $org->country,
            'currency' => $org->currency,
            'timezone' => $org->timezone,
            'status' => $org->status,
            'verified' => $org->verified_at !== null,
            'default_commission_rate' => $org->default_commission_rate,
            'role' => tenant()->role() ?? 'platform',
            'brand' => $org->brandPayload(),
        ];
    }

    /** Shared with OrganizationController. */
    public function organizationPayload(): ?array
    {
        return $this->currentOrganizationPayload();
    }
}
