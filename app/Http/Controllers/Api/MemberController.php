<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** User management for the agency: add people, set roles, deactivate. */
class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requireRole('owner', 'admin');

        $members = Membership::with('user:id,name,email,phone,avatar_path,last_login_at,is_active')
            ->where('organization_id', tenant()->id())
            ->when($request->role, fn ($q, $v) => $q->where('role', $v))
            ->when($request->q, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))
            ->orderByRaw("FIELD(role, 'owner', 'admin', 'broker', 'agent', 'customer')")
            ->paginate($this->perPage());

        return response()->json($members);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireRole('owner', 'admin');
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'phone' => 'nullable|string|max:32',
            'role' => ['required', Rule::in(['admin', 'broker', 'agent', 'customer'])],
            'password' => 'required|string|min:8',
        ]);

        $membership = DB::transaction(function () use ($data) {
            $user = User::firstOrCreate(['email' => strtolower($data['email'])], [
                'name' => $data['name'], 'phone' => $data['phone'] ?? null, 'password' => $data['password'],
            ]);
            abort_if(Membership::where('organization_id', tenant()->id())->where('user_id', $user->id)->exists(), 422, 'This person is already a member.');

            $membership = Membership::create(['organization_id' => tenant()->id(), 'user_id' => $user->id, 'role' => $data['role'], 'is_active' => true, 'joined_at' => now()]);
            $this->ensureProfile($user, $data['role']);

            return $membership;
        });

        AuditLogger::log('member.added', $membership->user, "Added {$data['email']} as {$data['role']}");

        return response()->json(['data' => $membership->load('user')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireRole('owner', 'admin');
        $membership = Membership::where('organization_id', tenant()->id())->findOrFail($id);
        abort_if($membership->role === 'owner' && tenant()->role() !== 'owner' && ! auth()->user()->isPlatformOwner(), 403, 'Only the owner can change the owner.');
        abort_if($membership->user_id === auth()->id() && $request->has('is_active') && ! $request->boolean('is_active'), 422, 'You cannot deactivate yourself.');

        $data = $request->validate(['role' => ['sometimes', Rule::in(Membership::ROLES)], 'is_active' => 'sometimes|boolean']);
        $membership->update($data);
        if (isset($data['role'])) {
            $this->ensureProfile($membership->user, $data['role']);
        }
        AuditLogger::log('member.updated', $membership->user, "Updated membership of {$membership->user->email}", $data);

        return response()->json(['data' => $membership->load('user')]);
    }

    /** Staff roles get an agent profile; customers get a CRM record. */
    private function ensureProfile(User $user, string $role): void
    {
        if (in_array($role, ['agent', 'broker'], true)) {
            $agent = Agent::withTrashed()->firstOrNew(['user_id' => $user->id]);
            $agent->fill(['type' => $role, 'commission_rate' => $agent->commission_rate ?? tenant()->organization()->default_commission_rate]);
            $agent->deleted_at = null;
            $agent->save();
        }
        if ($role === 'customer' && ! Customer::where('user_id', $user->id)->exists()) {
            Customer::create(['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'status' => 'active', 'source' => 'admin']);
        }
    }
}
