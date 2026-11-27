<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Property;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin & verifications: one queue for listings, agents, documents and customer KYC. */
class VerificationController extends Controller
{
    public function index(): JsonResponse
    {
        $this->requireRole('owner', 'admin');

        return response()->json(['data' => [
            'properties' => Property::with(['cover', 'agent.user:id,name'])->withCount('documents')->where('verification_status', 'pending')->latest('updated_at')->get(),
            'agents' => Agent::with('user:id,name,email,phone,avatar_path')->withCount('documents')->where('verification_status', 'pending')->latest()->get(),
            'documents' => Document::with(['uploader:id,name', 'documentable'])->where('verification_status', 'pending')->latest()->get(),
            'customers' => Customer::withCount('documents')->where('kyc_status', 'pending')->whereHas('documents')->latest()->get(),
            'counts' => [
                'properties' => Property::where('verification_status', 'pending')->count(),
                'agents' => Agent::where('verification_status', 'pending')->count(),
                'documents' => Document::where('verification_status', 'pending')->count(),
                'customers' => Customer::where('kyc_status', 'pending')->whereHas('documents')->count(),
            ],
        ]]);
    }

    public function decide(Request $request, string $type, int $id): JsonResponse
    {
        $this->requireRole('owner', 'admin');
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'reason' => 'required_if:decision,reject|nullable|string|max:500']);
        $approve = $data['decision'] === 'approve';

        switch ($type) {
            case 'property':
                $item = Property::findOrFail($id);
                $item->update([
                    'verification_status' => $approve ? 'verified' : 'rejected', 'verified_at' => $approve ? now() : null,
                    'verified_by' => auth()->id(), 'rejection_reason' => $approve ? null : $data['reason'],
                    'status' => $approve && $item->status === 'draft' ? 'available' : $item->status,
                ]);
                NotificationService::toAgent($item->agent, 'verification.decided', "Listing {$item->reference} ".($approve ? 'verified ✓' : 'rejected'), $approve ? 'It is now live.' : $data['reason'], "/properties/{$item->id}");
                break;
            case 'agent':
                $item = Agent::findOrFail($id);
                $item->update(['verification_status' => $approve ? 'verified' : 'rejected', 'verified_at' => $approve ? now() : null, 'verified_by' => auth()->id(), 'rejection_reason' => $approve ? null : $data['reason']]);
                NotificationService::toAgent($item, 'verification.decided', 'Your agent profile was '.($approve ? 'verified ✓' : 'rejected'), $approve ? null : $data['reason'], "/agents/{$item->id}");
                break;
            case 'document':
                $item = Document::findOrFail($id);
                $item->update(['verification_status' => $approve ? 'verified' : 'rejected', 'verified_at' => now(), 'verified_by' => auth()->id(), 'rejection_reason' => $approve ? null : $data['reason']]);
                NotificationService::send($item->uploader, 'verification.decided', "Document \"{$item->name}\" ".($approve ? 'verified ✓' : 'rejected'), $approve ? null : $data['reason'], '/documents');
                break;
            case 'customer':
                $item = Customer::findOrFail($id);
                $item->update(['kyc_status' => $approve ? 'verified' : 'rejected']);
                NotificationService::toCustomer($item, 'verification.decided', 'Your identity verification was '.($approve ? 'approved ✓' : 'rejected'), $approve ? null : $data['reason'], '/documents');
                break;
            default:
                abort(404);
        }

        AuditLogger::log("verification.{$data['decision']}", $item, ucfirst($type)." #{$id} ".($approve ? 'approved' : 'rejected').($approve ? '' : ": {$data['reason']}"));

        return response()->json(['data' => $item->fresh()]);
    }
}
