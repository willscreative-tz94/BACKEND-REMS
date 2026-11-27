<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Buyer/customer CRM: leads, requirements, assignment, history. */
class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');

        $query = Customer::visible()->with('assignedAgent.user:id,name')
            ->withCount(['viewings', 'sales'])
            ->when($request->q, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")->orWhere('phone', 'like', "%{$v}%")))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->assigned_agent_id, fn ($q, $v) => $q->where('assigned_agent_id', $v))
            ->latest();

        return response()->json($request->boolean('all') ? ['data' => $query->limit(500)->get(['id', 'name', 'email', 'phone', 'type'])] : $query->paginate($this->perPage()));
    }

    public function show(Customer $customer): JsonResponse
    {
        abort_unless(Customer::visible()->whereKey($customer->id)->exists(), 404);

        $customer->load([
            'assignedAgent.user:id,name,email,phone',
            'user:id,name,email,last_login_at',
            'viewings' => fn ($q) => $q->with('property:id,title,reference')->latest('scheduled_at')->limit(20),
            'sales' => fn ($q) => $q->with('property:id,title,reference')->latest(),
            'payments' => fn ($q) => $q->latest()->limit(20),
            'contracts' => fn ($q) => $q->latest(),
            'documents' => fn ($q) => $q->latest(),
        ]);

        return response()->json(['data' => $customer]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        $data = $request->validate($this->rules());
        $data['assigned_agent_id'] ??= $this->currentAgent()?->id;

        $customer = Customer::create($data);
        AuditLogger::log('customer.created', $customer, "Added customer {$customer->name}");
        if ($customer->assignedAgent && $customer->assignedAgent->user_id !== auth()->id()) {
            NotificationService::toAgent($customer->assignedAgent, 'customer.assigned', "New customer assigned: {$customer->name}", null, "/customers/{$customer->id}");
        }

        return response()->json(['data' => $customer], 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        abort_unless(Customer::visible()->whereKey($customer->id)->exists(), 404);

        $before = $customer->assigned_agent_id;
        $customer->update($request->validate($this->rules(true)));

        if ($customer->assigned_agent_id && $before !== $customer->assigned_agent_id) {
            NotificationService::toAgent($customer->assignedAgent, 'customer.assigned', "Customer assigned to you: {$customer->name}", null, "/customers/{$customer->id}");
        }

        return response()->json(['data' => $customer->fresh('assignedAgent.user')]);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->requireRole('owner', 'admin');
        $customer->delete();
        AuditLogger::log('customer.deleted', $customer, "Archived customer {$customer->name}");

        return response()->json(['message' => 'Customer archived.']);
    }

    private function rules(bool $update = false): array
    {
        $r = $update ? 'sometimes' : 'required';

        return [
            'name' => "{$r}|string|max:120",
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:32',
            'national_id' => 'nullable|string|max:64',
            'type' => ['sometimes', Rule::in(['buyer', 'tenant', 'investor', 'seller', 'landlord'])],
            'status' => ['sometimes', Rule::in(['lead', 'active', 'negotiating', 'closed', 'lost'])],
            'source' => 'nullable|string|max:64',
            'budget_min' => 'nullable|numeric|min:0',
            'budget_max' => 'nullable|numeric|min:0',
            'preferred_type' => 'nullable|string|max:64',
            'preferred_locations' => 'nullable|string|max:255',
            'preferred_bedrooms' => 'nullable|integer|min:0|max:20',
            'kyc_status' => ['sometimes', Rule::in(['pending', 'verified', 'rejected'])],
            'notes' => 'nullable|string|max:5000',
            'assigned_agent_id' => ['nullable', Rule::exists('agents', 'id')->where('organization_id', tenant()->id())],
        ];
    }
}
