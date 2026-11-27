<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Sale;
use App\Services\AuditLogger;
use App\Services\ContractTemplates;
use App\Services\NotificationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Contracts & documentation: generate from template, edit, send, e-sign, download PDF. */
class ContractController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Contract::visible()->with(['property:id,title,reference', 'customer:id,name', 'agent.user:id,name'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->q, fn ($q, $v) => $q->where(fn ($w) => $w->where('number', 'like', "%{$v}%")->orWhere('title', 'like', "%{$v}%")))
            ->latest();

        return response()->json($query->paginate($this->perPage()));
    }

    public function show(Contract $contract): JsonResponse
    {
        abort_unless(Contract::visible()->whereKey($contract->id)->exists(), 404);
        abort_if($this->isCustomer() && $contract->status === 'draft', 404);

        return response()->json(['data' => $contract->load(['property', 'customer', 'agent.user:id,name', 'sale'])]);
    }

    /** Draft from the template for its type; prefilled from the deal when sale_id is given. */
    public function store(Request $request): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        $data = $request->validate([
            'type' => 'required|in:sale_agreement,lease_agreement,listing_mandate,offer_letter',
            'sale_id' => ['nullable', Rule::exists('sales', 'id')->where('organization_id', tenant()->id())],
            'property_id' => ['required_without:sale_id', 'nullable', Rule::exists('properties', 'id')->where('organization_id', tenant()->id())],
            'customer_id' => ['required_without:sale_id', 'nullable', Rule::exists('customers', 'id')->where('organization_id', tenant()->id())],
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'title' => 'nullable|string|max:190',
            'body' => 'nullable|string',
        ]);

        $sale = isset($data['sale_id']) ? Sale::with(['property', 'customer', 'agent.user'])->find($data['sale_id']) : null;
        $property = $sale?->property ?? Property::findOrFail($data['property_id']);
        $customer = $sale?->customer ?? Customer::findOrFail($data['customer_id']);
        $template = ContractTemplates::render($data['type'], $property, $customer, $sale, $data);

        $contract = Contract::create([
            'type' => $data['type'],
            'sale_id' => $sale?->id,
            'property_id' => $property->id,
            'customer_id' => $customer->id,
            'agent_id' => $sale?->agent_id ?? $property->agent_id ?? $this->currentAgent()?->id,
            'title' => $data['title'] ?? $template['title'],
            'body' => $data['body'] ?? $template['body'],
            'value' => $sale?->agreed_price ?? $sale?->offer_amount ?? $property->price,
            'currency' => $sale?->currency ?? $property->currency,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'status' => 'draft',
        ]);

        AuditLogger::log('contract.created', $contract, "Drafted {$contract->number}");

        return response()->json(['data' => $contract], 201);
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        $this->ensureCanManage($contract->agent_id);
        abort_if(in_array($contract->status, ['signed', 'active'], true), 422, 'Signed contracts cannot be edited.');

        $contract->update($request->validate([
            'title' => 'sometimes|string|max:190',
            'body' => 'sometimes|string',
            'value' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'status' => 'sometimes|in:draft,terminated,expired',
        ]));

        return response()->json(['data' => $contract]);
    }

    public function send(Contract $contract): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        $this->ensureCanManage($contract->agent_id);
        abort_unless($contract->status === 'draft', 422, 'Only drafts can be sent.');

        $contract->update(['status' => 'sent', 'sent_at' => now()]);
        NotificationService::toCustomer($contract->customer, 'contract.sent', "Please review and sign: {$contract->title}", $contract->number, "/contracts/{$contract->id}");
        AuditLogger::log('contract.sent', $contract, "Sent {$contract->number} to {$contract->customer->name}");

        return response()->json(['data' => $contract]);
    }

    /** E-signature: the customer signs by typing their full name; the agency countersigns. */
    public function sign(Request $request, Contract $contract): JsonResponse
    {
        abort_unless(Contract::visible()->whereKey($contract->id)->exists(), 404);
        $data = $request->validate(['signature' => 'required|string|max:120', 'accept' => 'accepted']);

        if ($this->isCustomer()) {
            abort_unless($contract->status === 'sent', 422, 'This contract is not awaiting your signature.');
            $contract->update([
                'customer_signature' => $data['signature'], 'customer_signed_at' => now(), 'customer_signed_ip' => $request->ip(),
                'status' => $contract->agency_signed_at ? 'signed' : 'sent',
            ]);
            NotificationService::toAgent($contract->agent, 'contract.signed', "{$contract->customer->name} signed {$contract->number}", $contract->title, "/contracts/{$contract->id}");
            NotificationService::toAdmins('contract.signed', "{$contract->customer->name} signed {$contract->number}", $contract->title, "/contracts/{$contract->id}");
        } else {
            $this->requireRole('owner', 'admin', 'broker');
            abort_unless(in_array($contract->status, ['sent', 'draft'], true), 422, 'This contract cannot be countersigned.');
            $contract->update([
                'agency_signature' => $data['signature'], 'agency_signed_at' => now(),
                'status' => $contract->customer_signed_at ? 'signed' : $contract->status,
            ]);
        }

        $contract->refresh();
        if ($contract->status === 'signed') {
            if ($contract->sale && $contract->sale->status === 'accepted') {
                $contract->sale->update(['status' => 'contract']);
            }
            if ($contract->type === 'lease_agreement' && $contract->start_date && $contract->start_date->isPast()) {
                $contract->update(['status' => 'active']);
            }
            NotificationService::toCustomer($contract->customer, 'contract.signed', "{$contract->number} is fully signed", 'Download your copy from Contracts.', "/contracts/{$contract->id}");
        }
        AuditLogger::log('contract.signed', $contract, "Signature on {$contract->number} by {$data['signature']}");

        return response()->json(['data' => $contract->fresh(['property', 'customer', 'agent.user'])]);
    }

    public function pdf(Contract $contract): Response
    {
        abort_unless(Contract::visible()->whereKey($contract->id)->exists(), 404);
        $contract->load(['property', 'customer', 'agent.user']);
        $org = tenant()->organization()->load('brand');

        return Pdf::loadView('pdf.contract', ['contract' => $contract, 'org' => $org, 'brand' => $org->brandPayload()])
            ->download("{$contract->number}.pdf");
    }

    /** Preview a template without saving (form "Generate" button). */
    public function template(Request $request): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        $data = $request->validate([
            'type' => 'required|in:sale_agreement,lease_agreement,listing_mandate,offer_letter',
            'sale_id' => 'nullable|integer', 'property_id' => 'nullable|integer', 'customer_id' => 'nullable|integer',
        ]);
        $sale = ! empty($data['sale_id']) ? Sale::with(['property', 'customer', 'agent.user'])->findOrFail($data['sale_id']) : null;
        $property = $sale?->property ?? Property::findOrFail($data['property_id']);
        $customer = $sale?->customer ?? Customer::findOrFail($data['customer_id']);

        return response()->json(['data' => ContractTemplates::render($data['type'], $property, $customer, $sale)]);
    }
}
