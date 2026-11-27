<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertyFavorite;
use App\Models\Sale;
use App\Models\Viewing;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Role-aware home screen: agency KPIs for staff, "my journey" for customers. */
class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => $this->isCustomer() ? $this->customer() : $this->staff()]);
    }

    private function staff(): array
    {
        $scopeAgent = ! $this->isAdmin() ? $this->currentAgent() : null;
        $props = fn () => Property::query()->when($scopeAgent, fn ($q) => $q->whereIn('agent_id', $this->role() === 'broker' ? $scopeAgent->teamIds() : [$scopeAgent->id]));
        $monthStart = now()->startOfMonth();

        $salesByMonth = Sale::visible()->where('status', 'completed')->where('closed_at', '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw("DATE_FORMAT(closed_at, '%Y-%m') as month, COUNT(*) as deals, SUM(COALESCE(agreed_price, offer_amount)) as value")
            ->groupBy('month')->orderBy('month')->get()->keyBy('month');
        $revenueByMonth = Payment::visible()->where('status', 'completed')->where('paid_at', '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as month, SUM(amount) as total")->groupBy('month')->pluck('total', 'month');

        $months = collect(range(11, 0))->map(fn ($i) => now()->subMonths($i)->format('Y-m'));

        return [
            'kpis' => [
                'active_listings' => $props()->whereIn('status', ['available', 'under_offer'])->count(),
                'listings_this_month' => $props()->where('created_at', '>=', $monthStart)->count(),
                'pending_verification' => $props()->where('verification_status', 'pending')->count(),
                'customers' => Customer::visible()->count(),
                'new_leads' => Customer::visible()->where('status', 'lead')->count(),
                'upcoming_viewings' => Viewing::visible()->where('scheduled_at', '>=', now())->whereIn('status', ['requested', 'confirmed'])->count(),
                'viewing_requests' => Viewing::visible()->where('status', 'requested')->count(),
                'open_deals' => Sale::visible()->whereIn('status', ['offer', 'negotiating', 'accepted', 'contract'])->count(),
                'pipeline_value' => (float) Sale::visible()->whereIn('status', ['offer', 'negotiating', 'accepted', 'contract'])->sum(DB::raw('COALESCE(agreed_price, offer_amount)')),
                'sales_this_month' => (float) Sale::visible()->where('status', 'completed')->where('closed_at', '>=', $monthStart)->sum(DB::raw('COALESCE(agreed_price, offer_amount)')),
                'revenue_this_month' => (float) Payment::visible()->where('status', 'completed')->where('paid_at', '>=', $monthStart)->sum('amount'),
                'commission_pending' => (float) Commission::visible()->whereIn('status', ['pending', 'approved'])->sum('amount'),
                'unread_messages' => Conversation::visible()->whereHas('messages', fn ($q) => $q->whereNull('read_at')->where('sender_id', '!=', auth()->id()))->count(),
                'contracts_awaiting' => Contract::visible()->where('status', 'sent')->count(),
            ],
            'charts' => [
                'months' => $months->map(fn ($m) => \Carbon\Carbon::createFromFormat('Y-m', $m)->format('M y'))->values(),
                'sales_value' => $months->map(fn ($m) => (float) ($salesByMonth[$m]->value ?? 0))->values(),
                'deals' => $months->map(fn ($m) => (int) ($salesByMonth[$m]->deals ?? 0))->values(),
                'revenue' => $months->map(fn ($m) => (float) ($revenueByMonth[$m] ?? 0))->values(),
                'listings_by_type' => $props()->selectRaw('property_type as label, COUNT(*) as value')->groupBy('property_type')->get(),
                'listings_by_status' => $props()->selectRaw('status as label, COUNT(*) as value')->groupBy('status')->get(),
                'pipeline' => Sale::visible()->selectRaw('status as label, COUNT(*) as value')->groupBy('status')->get(),
            ],
            'upcoming_viewings' => Viewing::visible()->with(['property:id,title,reference', 'customer:id,name', 'agent.user:id,name'])
                ->where('scheduled_at', '>=', now())->whereIn('status', ['requested', 'confirmed'])->orderBy('scheduled_at')->limit(6)->get(),
            'recent_deals' => Sale::visible()->with(['property:id,title', 'customer:id,name'])->latest()->limit(6)->get(),
            'top_agents' => $this->isAdmin() || $this->role() === 'broker' ? \App\Models\Agent::with('user:id,name,avatar_path')
                ->withCount(['sales as closed' => fn ($q) => $q->where('status', 'completed')])
                ->withSum(['sales as volume' => fn ($q) => $q->where('status', 'completed')], 'agreed_price')
                ->orderByDesc('volume')->limit(5)->get() : [],
            'recent_listings' => $props()->with('cover')->latest()->limit(4)->get(),
        ];
    }

    private function customer(): array
    {
        $customer = $this->currentCustomer();
        $cid = $customer?->id ?? 0;

        return [
            'customer' => $customer,
            'kpis' => [
                'saved' => PropertyFavorite::where('user_id', auth()->id())->count(),
                'upcoming_viewings' => Viewing::where('customer_id', $cid)->where('scheduled_at', '>=', now())->whereIn('status', ['requested', 'confirmed'])->count(),
                'open_offers' => Sale::where('customer_id', $cid)->whereIn('status', ['offer', 'negotiating', 'accepted', 'contract'])->count(),
                'contracts_to_sign' => Contract::where('customer_id', $cid)->where('status', 'sent')->whereNull('customer_signed_at')->count(),
                'paid' => (float) Payment::where('customer_id', $cid)->where('status', 'completed')->sum('amount'),
                'balance' => (float) Sale::where('customer_id', $cid)->whereIn('status', ['accepted', 'contract'])->get()->sum('balance'),
            ],
            'upcoming_viewings' => Viewing::with(['property:id,title,reference,address,city', 'property.cover', 'agent.user:id,name,phone'])
                ->where('customer_id', $cid)->where('scheduled_at', '>=', now())->whereIn('status', ['requested', 'confirmed'])->orderBy('scheduled_at')->limit(5)->get(),
            'deals' => Sale::with(['property:id,title,reference', 'property.cover'])->where('customer_id', $cid)->latest()->limit(5)->get(),
            'recommended' => Property::publiclyListed()->with('cover')
                ->when($customer?->budget_max, fn ($q, $v) => $q->where('price', '<=', $v))
                ->when($customer?->type === 'tenant', fn ($q) => $q->where('listing_type', 'rent'))
                ->when($customer?->type === 'buyer', fn ($q) => $q->where('listing_type', 'sale'))
                ->orderByDesc('is_featured')->latest()->limit(6)->get(),
            'assigned_agent' => $customer?->assignedAgent?->load('user:id,name,email,phone,avatar_path'),
        ];
    }
}
