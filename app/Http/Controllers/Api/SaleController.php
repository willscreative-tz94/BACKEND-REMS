<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Sale;
use App\Services\AuditLogger;
use App\Services\CommissionService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Online sales pipeline: offer → negotiating → accepted → contract → completed. */
class SaleController extends Controller
{
    /** Allowed next statuses from each status. */
    private const FLOW = [
        'offer' => ['negotiating', 'accepted', 'rejected', 'cancelled'],
        'negotiating' => ['accepted', 'rejected', 'cancelled'],
        'accepted' => ['contract', 'cancelled'],
        'contract' => ['completed', 'cancelled'],
        'completed' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Sale::visible()
            ->with(['property:id,title,reference,city,listing_type', 'property.cover', 'customer:id,name,email,phone', 'agent.user:id,name'])
            ->when($request->status, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->q, fn ($q, $v) => $q->where(fn ($w) => $w->where('reference', 'like', "%{$v}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$v}%"))
                ->orWhereHas('property', fn ($p) => $p->where('title', 'like', "%{$v}%"))))
            ->latest();

        return response()->json($query->paginate($this->perPage()));
    }

    public function show(Sale $sale): JsonResponse
    {
        abort_unless(Sale::visible()->whereKey($sale->id)->exists(), 404);
        $sale->load(['property.cover', 'property.agent.user:id,name', 'customer', 'agent.user:id,name,email,phone',
            'payments' => fn ($q) => $q->latest(), 'contracts', 'commissions.agent.user:id,name']);

        $data = $sale->toArray();
        $data['next_statuses'] = $this->isStaff() ? self::FLOW[$sale->status] : [];

        return response()->json(['data' => $data]);
    }

    /** Customers make offers on listings; staff can also record an offer on a customer's behalf. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'property_id' => ['required', Rule::exists('properties', 'id')->where('organization_id', tenant()->id())],
            'customer_id' => [$this->isCustomer() ? 'nullable' : 'required', Rule::exists('customers', 'id')->where('organization_id', tenant()->id())],
            'offer_amount' => 'required|numeric|min:1',
            'deposit_amount' => 'nullable|numeric|min:0',
            'payment_plan' => 'nullable|in:full,installments,mortgage,monthly_rent',
            'notes' => 'nullable|string|max:3000',
        ]);

        $property = Property::findOrFail($data['property_id']);
        abort_unless(in_array($property->status, ['available', 'under_offer'], true), 422, 'This property is no longer available.');

        if ($this->isCustomer()) {
            $customer = $this->currentCustomer();
            abort_unless($customer, 422, 'Complete your customer profile first.');
            $data['customer_id'] = $customer->id;
        }

        $sale = Sale::create([
            ...$data,
            'agent_id' => $property->agent_id ?? $this->currentAgent()?->id,
            'type' => $property->listing_type === 'rent' ? 'rental' : 'sale',
            'currency' => $property->currency,
            'payment_plan' => $data['payment_plan'] ?? ($property->listing_type === 'rent' ? 'monthly_rent' : 'full'),
            'offer_date' => now()->toDateString(),
            'status' => 'offer',
        ])->load(['property', 'customer', 'agent.user']);

        Customer::whereKey($sale->customer_id)->update(['status' => 'negotiating']);
        AuditLogger::log('sale.offer', $sale, "Offer {$sale->reference} of {$sale->currency} ".number_format((float) $sale->offer_amount)." on {$property->reference}");

        $msg = "{$sale->customer->name} offered {$sale->currency} ".number_format((float) $sale->offer_amount);
        $sale->agent
            ? NotificationService::toAgent($sale->agent, 'sale.offer', "New offer on {$property->title}", $msg, "/sales/{$sale->id}")
            : NotificationService::toAdmins('sale.offer', "New offer on {$property->title}", $msg, "/sales/{$sale->id}");
        if (! $this->isCustomer()) {
            NotificationService::toCustomer($sale->customer, 'sale.offer', "Offer recorded on {$property->title}", $msg, "/sales/{$sale->id}");
        }

        return response()->json(['data' => $sale], 201);
    }

    public function update(Request $request, Sale $sale): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        $this->ensureCanManage($sale->agent_id);

        $sale->update($request->validate([
            'agreed_price' => 'nullable|numeric|min:0',
            'deposit_amount' => 'nullable|numeric|min:0',
            'payment_plan' => 'sometimes|in:full,installments,mortgage,monthly_rent',
            'notes' => 'nullable|string|max:3000',
            'agent_id' => ['nullable', Rule::exists('agents', 'id')->where('organization_id', tenant()->id())],
        ]));

        return response()->json(['data' => $sale->fresh()]);
    }

    /** Move the deal along its pipeline; side effects keep property, customer and commissions in step. */
    public function transition(Request $request, Sale $sale): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        $this->ensureCanManage($sale->agent_id);
        $data = $request->validate(['status' => 'required|string', 'agreed_price' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:1000']);

        abort_unless(in_array($data['status'], self::FLOW[$sale->status], true), 422, "A deal cannot move from {$sale->status} to {$data['status']}.");
        if ($data['status'] === 'completed') {
            $this->requireRole('owner', 'admin', 'broker');
        }

        DB::transaction(function () use ($sale, $data) {
            $updates = ['status' => $data['status']];
            if (! empty($data['agreed_price'])) {
                $updates['agreed_price'] = $data['agreed_price'];
            }
            if ($data['status'] === 'accepted') {
                $updates['accepted_at'] = now();
                $updates['agreed_price'] ??= $sale->agreed_price ?? $sale->offer_amount;
                $sale->property->update(['status' => 'under_offer']);
            }
            if ($data['status'] === 'completed') {
                $updates['closed_at'] = now();
                $sale->property->update(['status' => $sale->type === 'rental' ? 'rented' : 'sold']);
                $sale->customer->update(['status' => 'closed']);
            }
            if (in_array($data['status'], ['cancelled', 'rejected'], true)) {
                $updates['closed_at'] = now();
                // Release the listing if no other live deal holds it.
                $otherLive = Sale::where('property_id', $sale->property_id)->whereKeyNot($sale->id)->whereIn('status', ['accepted', 'contract'])->exists();
                if (! $otherLive && $sale->property->status === 'under_offer') {
                    $sale->property->update(['status' => 'available']);
                }
            }
            if (! empty($data['note'])) {
                $updates['notes'] = trim(($sale->notes ? $sale->notes."\n" : '').now()->format('Y-m-d').': '.$data['note']);
            }
            $sale->update($updates);

            if ($data['status'] === 'completed') {
                CommissionService::generateForSale($sale->fresh(['agent.broker', 'agent.user']));
            }
        });

        AuditLogger::log('sale.'.$data['status'], $sale, "Deal {$sale->reference} moved to {$data['status']}");
        NotificationService::toCustomer($sale->customer, 'sale.status', "Your deal {$sale->reference} is now ".str_replace('_', ' ', $data['status']), $sale->property->title, "/sales/{$sale->id}");

        return $this->show($sale->fresh());
    }
}
