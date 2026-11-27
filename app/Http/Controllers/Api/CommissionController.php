<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Commission management: generated on completed deals, approved and paid out by admins. */
class CommissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');

        $base = fn () => Commission::visible()
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->agent_id, fn ($q, $v) => $q->where('agent_id', $v));

        $page = $base()->with(['sale:id,reference,property_id,agreed_price,closed_at', 'sale.property:id,title,reference', 'agent.user:id,name', 'approver:id,name'])
            ->latest()->paginate($this->perPage());

        $summary = [
            'pending' => (float) Commission::visible()->where('status', 'pending')->sum('amount'),
            'approved' => (float) Commission::visible()->where('status', 'approved')->sum('amount'),
            'paid' => (float) Commission::visible()->where('status', 'paid')->sum('amount'),
        ];

        return response()->json([...$page->toArray(), 'summary' => $summary]);
    }

    public function approve(Commission $commission): JsonResponse
    {
        $this->requireRole('owner', 'admin');
        abort_unless($commission->status === 'pending', 422, 'Only pending commissions can be approved.');
        $commission->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        NotificationService::toAgent($commission->agent, 'commission.approved', 'Commission approved', number_format((float) $commission->amount, 2)." {$commission->currency} on {$commission->sale->reference}", '/commissions');
        AuditLogger::log('commission.approved', $commission, "Approved commission #{$commission->id}");

        return response()->json(['data' => $commission->fresh(['agent.user', 'sale'])]);
    }

    public function pay(Request $request, Commission $commission): JsonResponse
    {
        $this->requireRole('owner', 'admin');
        abort_unless($commission->status === 'approved', 422, 'Approve the commission before paying it.');
        $data = $request->validate(['payment_reference' => 'nullable|string|max:120']);
        $commission->update(['status' => 'paid', 'paid_at' => now(), 'payment_reference' => $data['payment_reference'] ?? null]);
        NotificationService::toAgent($commission->agent, 'commission.paid', 'Commission paid', number_format((float) $commission->amount, 2)." {$commission->currency} — ref ".($data['payment_reference'] ?? 'n/a'), '/commissions');
        AuditLogger::log('commission.paid', $commission, "Paid commission #{$commission->id}");

        return response()->json(['data' => $commission->fresh(['agent.user', 'sale'])]);
    }

    public function update(Request $request, Commission $commission): JsonResponse
    {
        $this->requireRole('owner', 'admin');
        abort_if($commission->status === 'paid', 422, 'Paid commissions cannot be changed.');
        $data = $request->validate(['rate' => 'sometimes|numeric|min:0|max:100', 'status' => 'sometimes|in:pending,cancelled', 'notes' => 'nullable|string|max:1000']);
        if (isset($data['rate'])) {
            $data['amount'] = round((float) $commission->base_amount * $data['rate'] / 100, 2);
        }
        $commission->update($data);

        return response()->json(['data' => $commission]);
    }
}
