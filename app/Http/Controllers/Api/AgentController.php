<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Membership;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Agent & broker management: profiles, teams, licences, verification, performance. */
class AgentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Agent::with(['user:id,name,email,phone,avatar_path,is_active', 'broker.user:id,name'])
            ->withCount([
                'properties as active_listings_count' => fn ($q) => $q->whereIn('status', ['available', 'under_offer']),
                'sales as closed_deals_count' => fn ($q) => $q->where('status', 'completed'),
                'viewings as upcoming_viewings_count' => fn ($q) => $q->where('scheduled_at', '>=', now())->whereIn('status', ['requested', 'confirmed']),
            ])
            ->withSum(['commissions as commission_earned' => fn ($q) => $q->whereIn('status', ['approved', 'paid'])], 'amount')
            ->when($request->q, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->verification_status, fn ($q, $v) => $q->where('verification_status', $v))
            ->when($request->broker_id, fn ($q, $v) => $q->where('broker_id', $v));

        // Customers only see verified agents.
        if ($this->isCustomer()) {
            $query->where('verification_status', 'verified');
        }

        return response()->json($request->boolean('all') ? ['data' => $query->get()] : $query->paginate($this->perPage()));
    }

    public function show(Agent $agent): JsonResponse
    {
        $agent->load(['user:id,name,email,phone,avatar_path', 'broker.user:id,name', 'teamMembers.user:id,name,avatar_path'])
            ->loadCount(['properties', 'customers', 'sales as closed_deals_count' => fn ($q) => $q->where('status', 'completed')]);

        $data = $agent->toArray();
        $data['listings'] = $agent->properties()->with('cover')->whereIn('status', ['available', 'under_offer'])->latest()->limit(12)->get();

        if ($this->isStaff()) {
            $data['documents'] = $agent->documents()->latest()->get();
            $data['performance'] = [
                'sales_value' => (float) $agent->sales()->where('status', 'completed')->sum(DB::raw('COALESCE(agreed_price, offer_amount)')),
                'commission_pending' => (float) $agent->commissions()->whereIn('status', ['pending', 'approved'])->sum('amount'),
                'commission_paid' => (float) $agent->commissions()->where('status', 'paid')->sum('amount'),
                'viewings_completed' => $agent->viewings()->where('status', 'completed')->count(),
                'conversion_rate' => ($v = $agent->viewings()->where('status', 'completed')->count())
                    ? round($agent->sales()->where('status', 'completed')->count() / $v * 100, 1) : 0,
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** Create the user (if new), the staff membership and the agent profile in one go. */
    public function store(Request $request): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker');
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'phone' => 'nullable|string|max:32',
            'password' => 'nullable|string|min:8',
            ...$this->profileRules(),
        ]);
        if ($this->role() === 'broker') {
            $data['type'] = 'agent';
            $data['broker_id'] = $this->currentAgent()?->id;
        }

        $agent = DB::transaction(function () use ($data) {
            $user = User::firstOrCreate(['email' => strtolower($data['email'])], [
                'name' => $data['name'], 'phone' => $data['phone'] ?? null, 'password' => $data['password'] ?? Str::password(12),
            ]);
            abort_if(Agent::where('user_id', $user->id)->exists(), 422, 'This person already has an agent profile in this agency.');

            Membership::updateOrCreate(
                ['organization_id' => tenant()->id(), 'user_id' => $user->id],
                ['role' => ($data['type'] ?? 'agent') === 'broker' ? 'broker' : 'agent', 'is_active' => true, 'joined_at' => now()],
            );

            return Agent::create([...collect($data)->only(array_keys($this->profileRules()))->all(), 'user_id' => $user->id]);
        });

        AuditLogger::log('agent.created', $agent, "Added {$agent->type} {$data['name']}");

        return response()->json(['data' => $agent->load('user')], 201);
    }

    public function update(Request $request, Agent $agent): JsonResponse
    {
        $isSelf = $agent->user_id === auth()->id();
        abort_unless($this->isAdmin() || $isSelf || ($this->role() === 'broker' && $agent->broker_id === $this->currentAgent()?->id), 403);

        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'phone' => 'nullable|string|max:32',
            ...$this->profileRules(true),
        ]);
        // Only admins change rates, type, team and verification.
        if (! $this->isAdmin()) {
            $data = collect($data)->except(['commission_rate', 'broker_split', 'type', 'broker_id', 'verification_status'])->all();
        }

        $agent->user->update(collect($data)->only(['name', 'phone'])->all());
        $agent->update(collect($data)->except(['name', 'phone'])->all());

        if (isset($data['type'])) {
            Membership::where('organization_id', tenant()->id())->where('user_id', $agent->user_id)
                ->whereIn('role', ['agent', 'broker'])->update(['role' => $data['type']]);
        }

        return response()->json(['data' => $agent->fresh(['user', 'broker.user'])]);
    }

    public function destroy(Agent $agent): JsonResponse
    {
        $this->requireRole('owner', 'admin');
        Membership::where('organization_id', tenant()->id())->where('user_id', $agent->user_id)->update(['is_active' => false]);
        $agent->delete();
        AuditLogger::log('agent.removed', $agent, "Removed agent #{$agent->id}");

        return response()->json(['message' => 'Agent removed.']);
    }

    private function profileRules(bool $update = false): array
    {
        return [
            'type' => [$update ? 'sometimes' : 'nullable', Rule::in(['agent', 'broker'])],
            'broker_id' => ['nullable', Rule::exists('agents', 'id')->where('organization_id', tenant()->id())->where('type', 'broker')],
            'license_number' => 'nullable|string|max:64',
            'license_expiry' => 'nullable|date',
            'specialization' => 'nullable|string|max:120',
            'bio' => 'nullable|string|max:3000',
            'languages' => 'nullable|array',
            'service_areas' => 'nullable|array',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'broker_split' => 'nullable|numeric|min:0|max:100',
        ];
    }
}
