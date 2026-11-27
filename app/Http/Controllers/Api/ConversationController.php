<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Property;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Customer ↔ agent messaging, optionally tied to a listing. */
class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $conversations = Conversation::visible()
            ->with(['property:id,title,reference', 'property.cover', 'customer:id,name,user_id', 'agent.user:id,name,avatar_path', 'latestMessage'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->whereNull('read_at')->where(fn ($w) => $w->where('sender_id', '!=', auth()->id())->orWhereNull('sender_id'))])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->q, fn ($q, $v) => $q->where('subject', 'like', "%{$v}%"))
            ->orderByDesc('last_message_at')
            ->paginate($this->perPage());

        return response()->json($conversations);
    }

    public function show(Conversation $conversation): JsonResponse
    {
        abort_unless(Conversation::visible()->whereKey($conversation->id)->exists(), 404);

        $conversation->messages()->whereNull('read_at')
            ->where(fn ($q) => $q->where('sender_id', '!=', auth()->id())->orWhereNull('sender_id'))
            ->update(['read_at' => now()]);

        return response()->json(['data' => $conversation->load([
            'property:id,title,reference,price,currency,city', 'property.cover', 'customer:id,name,email,phone,user_id',
            'agent.user:id,name,email,phone,avatar_path', 'messages.sender:id,name,avatar_path',
        ])]);
    }

    /** Start a thread: customers message a listing's agent; staff message a customer. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'property_id' => ['nullable', Rule::exists('properties', 'id')->where('organization_id', tenant()->id())],
            'customer_id' => [$this->isCustomer() ? 'nullable' : 'required', Rule::exists('customers', 'id')->where('organization_id', tenant()->id())],
            'agent_id' => ['nullable', Rule::exists('agents', 'id')->where('organization_id', tenant()->id())],
            'subject' => 'nullable|string|max:190',
            'body' => 'required|string|max:5000',
        ]);

        $property = isset($data['property_id']) ? Property::find($data['property_id']) : null;

        if ($this->isCustomer()) {
            $customer = $this->currentCustomer();
            abort_unless($customer, 422, 'Complete your customer profile first.');
            $agentId = $data['agent_id'] ?? $property?->agent_id ?? $customer->assigned_agent_id;
            // Reuse an open thread about the same listing.
            $conversation = Conversation::where('customer_id', $customer->id)->where('property_id', $property?->id)->where('agent_id', $agentId)->where('status', 'open')->first();
        } else {
            $customer = Customer::findOrFail($data['customer_id']);
            $agentId = $data['agent_id'] ?? $this->currentAgent()?->id ?? $property?->agent_id;
            $conversation = null;
        }

        $conversation ??= Conversation::create([
            'property_id' => $property?->id,
            'customer_id' => $customer->id,
            'agent_id' => $agentId,
            'subject' => $data['subject'] ?? ($property ? "About {$property->title}" : 'General enquiry'),
        ]);

        $this->postMessage($conversation, $data['body']);

        return response()->json(['data' => $conversation->fresh(['messages.sender:id,name'])], 201);
    }

    public function reply(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless(Conversation::visible()->whereKey($conversation->id)->exists(), 404);
        $data = $request->validate(['body' => 'required|string|max:5000']);

        $message = $this->postMessage($conversation, $data['body']);

        return response()->json(['data' => $message->load('sender:id,name,avatar_path')], 201);
    }

    public function close(Conversation $conversation): JsonResponse
    {
        abort_unless(Conversation::visible()->whereKey($conversation->id)->exists(), 404);
        $conversation->update(['status' => $conversation->status === 'open' ? 'closed' : 'open']);

        return response()->json(['data' => $conversation]);
    }

    private function postMessage(Conversation $conversation, string $body): Message
    {
        $message = Message::create(['conversation_id' => $conversation->id, 'sender_id' => auth()->id(), 'body' => $body]);
        $conversation->update(['last_message_at' => now(), 'status' => 'open']);

        $preview = str($body)->limit(140)->toString();
        $link = "/messages/{$conversation->id}";
        if ($this->isCustomer()) {
            $conversation->agent
                ? NotificationService::toAgent($conversation->agent, 'message.received', 'New message from '.auth()->user()->name, $preview, $link)
                : NotificationService::toAdmins('message.received', 'New message from '.auth()->user()->name, $preview, $link);
        } else {
            NotificationService::toCustomer($conversation->customer, 'message.received', 'New message from '.auth()->user()->name, $preview, $link);
            $conversation->customer->update(['last_contacted_at' => now()]);
        }

        return $message;
    }
}
