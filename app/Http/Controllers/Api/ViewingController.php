<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Viewing;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Property visit booking: request → confirm → complete (with feedback). */
class ViewingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Viewing::visible()
            ->with(['property:id,title,reference,address,city,neighborhood,latitude,longitude', 'property.cover', 'customer:id,name,phone,email', 'agent.user:id,name,phone'])
            ->when($request->status, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($request->property_id, fn ($q, $v) => $q->where('property_id', $v))
            ->when($request->from, fn ($q, $v) => $q->where('scheduled_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($request->to, fn ($q, $v) => $q->where('scheduled_at', '<=', Carbon::parse($v)->endOfDay()))
            ->when($request->boolean('upcoming'), fn ($q) => $q->where('scheduled_at', '>=', now())->whereIn('status', ['requested', 'confirmed']))
            ->orderBy($request->boolean('upcoming') ? 'scheduled_at' : 'scheduled_at', $request->boolean('upcoming') ? 'asc' : 'desc');

        return response()->json($query->paginate($this->perPage()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'property_id' => ['required', Rule::exists('properties', 'id')->where('organization_id', tenant()->id())],
            'customer_id' => [$this->isCustomer() ? 'nullable' : 'required', Rule::exists('customers', 'id')->where('organization_id', tenant()->id())],
            'scheduled_at' => 'required|date|after:now',
            'duration_minutes' => 'nullable|integer|min:15|max:240',
            'type' => 'nullable|in:in_person,virtual',
            'customer_notes' => 'nullable|string|max:2000',
            'agent_id' => ['nullable', Rule::exists('agents', 'id')->where('organization_id', tenant()->id())],
        ]);

        $property = Property::findOrFail($data['property_id']);
        abort_unless(in_array($property->status, ['available', 'under_offer'], true), 422, 'This property is not open for viewings.');

        if ($this->isCustomer()) {
            $customer = $this->currentCustomer() ?? Customer::create([
                'user_id' => auth()->id(), 'name' => auth()->user()->name, 'email' => auth()->user()->email,
                'phone' => auth()->user()->phone, 'status' => 'active', 'source' => 'portal',
            ]);
            $data['customer_id'] = $customer->id;
            $data['agent_id'] = $property->agent_id;
            $data['status'] = 'requested';
        } else {
            $data['agent_id'] ??= $property->agent_id ?? $this->currentAgent()?->id;
            $data['status'] = 'confirmed';
        }

        $start = Carbon::parse($data['scheduled_at']);
        $end = $start->copy()->addMinutes($data['duration_minutes'] ?? 45);
        if ($data['agent_id'] && $this->agentBusy($data['agent_id'], $start, $end)) {
            abort(422, 'The agent already has a viewing at that time. Please choose another slot.');
        }

        $viewing = Viewing::create($data)->load(['property', 'customer', 'agent.user']);

        $when = $start->format('D j M, H:i');
        if ($this->isCustomer()) {
            $viewing->agent
                ? NotificationService::toAgent($viewing->agent, 'viewing.requested', "Viewing requested: {$property->title}", "{$viewing->customer->name} — {$when}", '/viewings')
                : NotificationService::toAdmins('viewing.requested', "Viewing requested: {$property->title}", "{$viewing->customer->name} — {$when}", '/viewings');
        } else {
            NotificationService::toCustomer($viewing->customer, 'viewing.confirmed', "Viewing booked: {$property->title}", "Scheduled for {$when}.", '/viewings');
        }

        return response()->json(['data' => $viewing], 201);
    }

    public function update(Request $request, Viewing $viewing): JsonResponse
    {
        abort_unless(Viewing::visible()->whereKey($viewing->id)->exists(), 404);

        if ($this->isCustomer()) {
            $data = $request->validate([
                'status' => 'sometimes|in:cancelled',
                'scheduled_at' => 'sometimes|date|after:now',
                'customer_notes' => 'nullable|string|max:2000',
                'feedback' => 'nullable|string|max:2000',
                'interest_level' => 'nullable|integer|min:1|max:5',
            ]);
            if (isset($data['scheduled_at'])) {
                $data['status'] = 'requested';
            }
        } else {
            $data = $request->validate([
                'status' => ['sometimes', Rule::in(Viewing::STATUSES)],
                'scheduled_at' => 'sometimes|date',
                'duration_minutes' => 'sometimes|integer|min:15|max:240',
                'agent_id' => ['nullable', Rule::exists('agents', 'id')->where('organization_id', tenant()->id())],
                'agent_notes' => 'nullable|string|max:2000',
                'feedback' => 'nullable|string|max:2000',
                'interest_level' => 'nullable|integer|min:1|max:5',
            ]);
        }

        $before = $viewing->status;
        $viewing->update($data);
        $viewing->load(['property', 'customer', 'agent.user']);

        if ($before !== $viewing->status || isset($data['scheduled_at'])) {
            $when = $viewing->scheduled_at->format('D j M, H:i');
            $label = str_replace('_', ' ', $viewing->status);
            $this->isCustomer()
                ? NotificationService::toAgent($viewing->agent, 'viewing.updated', "Viewing {$label}: {$viewing->property->title}", "{$viewing->customer->name} — {$when}", '/viewings')
                : NotificationService::toCustomer($viewing->customer, 'viewing.updated', "Your viewing is {$label}", "{$viewing->property->title} — {$when}", '/viewings');
        }

        if ($viewing->status === 'completed') {
            $viewing->customer->update(['last_contacted_at' => now(), 'status' => $viewing->customer->status === 'lead' ? 'active' : $viewing->customer->status]);
        }

        return response()->json(['data' => $viewing]);
    }

    /** Booked slots for a property's agent on a date, so the booking form can grey them out. */
    public function availability(Request $request, Property $property): JsonResponse
    {
        $date = Carbon::parse($request->input('date', now()->toDateString()));
        $busy = Viewing::withoutGlobalScopes()->where('organization_id', tenant()->id())
            ->where('agent_id', $property->agent_id)->whereIn('status', ['requested', 'confirmed'])
            ->whereDate('scheduled_at', $date)->get(['scheduled_at', 'duration_minutes'])
            ->map(fn ($v) => ['start' => $v->scheduled_at->format('H:i'), 'end' => $v->scheduled_at->copy()->addMinutes($v->duration_minutes)->format('H:i')]);

        return response()->json(['data' => ['date' => $date->toDateString(), 'busy' => $busy, 'hours' => ['08:00', '18:00']]]);
    }

    private function agentBusy(int $agentId, Carbon $start, Carbon $end): bool
    {
        return Viewing::withoutGlobalScopes()->where('organization_id', tenant()->id())
            ->where('agent_id', $agentId)->whereIn('status', ['requested', 'confirmed'])
            ->whereBetween('scheduled_at', [$start->copy()->subHours(4), $end])->get()
            ->contains(fn ($v) => $v->scheduled_at < $end && $v->scheduled_at->copy()->addMinutes($v->duration_minutes) > $start);
    }
}
