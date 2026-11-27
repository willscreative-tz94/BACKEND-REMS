<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Commission;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Sale;
use App\Models\Viewing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Reports & analytics over a date range, with CSV export of each report. */
class ReportController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker');
        [$from, $to] = $this->range($request);

        $completed = Sale::where('status', 'completed')->whereBetween('closed_at', [$from, $to]);
        $viewingsDone = Viewing::where('status', 'completed')->whereBetween('scheduled_at', [$from, $to])->count();
        $closed = (clone $completed)->count();

        return response()->json(['data' => [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => [
                'sales_volume' => (float) (clone $completed)->sum(DB::raw('COALESCE(agreed_price, offer_amount)')),
                'deals_closed' => $closed,
                'sales_count' => (clone $completed)->where('type', 'sale')->count(),
                'rentals_count' => (clone $completed)->where('type', 'rental')->count(),
                'average_deal' => $closed ? round((float) (clone $completed)->avg(DB::raw('COALESCE(agreed_price, offer_amount)')), 2) : 0,
                'revenue_collected' => (float) Payment::where('status', 'completed')->whereBetween('paid_at', [$from, $to])->sum('amount'),
                'commission_total' => (float) Commission::whereIn('status', ['approved', 'paid', 'pending'])->whereBetween('created_at', [$from, $to])->sum('amount'),
                'new_listings' => Property::whereBetween('created_at', [$from, $to])->count(),
                'new_customers' => Customer::whereBetween('created_at', [$from, $to])->count(),
                'viewings_completed' => $viewingsDone,
                'viewing_to_deal_rate' => $viewingsDone ? round($closed / $viewingsDone * 100, 1) : 0,
                'avg_days_on_market' => (int) round((float) Sale::where('sales.status', 'completed')->whereBetween('closed_at', [$from, $to])
                    ->join('properties', 'properties.id', '=', 'sales.property_id')
                    ->avg(DB::raw('DATEDIFF(sales.closed_at, COALESCE(properties.published_at, properties.created_at))'))),
            ],
            'sales_by_month' => $this->salesByMonth($from, $to),
            'listings_by_city' => Property::selectRaw('city as label, COUNT(*) as value, AVG(price) as avg_price')->whereNotNull('city')->groupBy('city')->orderByDesc('value')->limit(10)->get(),
            'listings_by_type' => Property::selectRaw('property_type as label, COUNT(*) as value')->groupBy('property_type')->get(),
            'payments_by_method' => Payment::where('status', 'completed')->whereBetween('paid_at', [$from, $to])->selectRaw('method as label, SUM(amount) as value')->groupBy('method')->get(),
            'leads_by_source' => Customer::whereBetween('created_at', [$from, $to])->selectRaw("COALESCE(source, 'unknown') as label, COUNT(*) as value")->groupBy('label')->get(),
            'agent_performance' => $this->agentPerformance($from, $to),
        ]]);
    }

    /** CSV download: sales | payments | listings | agents | commissions | customers | viewings */
    public function export(Request $request, string $report): StreamedResponse
    {
        $this->requireRole('owner', 'admin', 'broker');
        [$from, $to] = $this->range($request);

        [$headers, $rows] = match ($report) {
            'sales' => [['Reference', 'Property', 'Customer', 'Agent', 'Type', 'Status', 'Offer', 'Agreed', 'Currency', 'Offer date', 'Closed'],
                Sale::with(['property:id,title', 'customer:id,name', 'agent.user:id,name'])->whereBetween('created_at', [$from, $to])->get()
                    ->map(fn ($s) => [$s->reference, $s->property?->title, $s->customer?->name, $s->agent?->user?->name, $s->type, $s->status, $s->offer_amount, $s->agreed_price, $s->currency, $s->offer_date?->toDateString(), $s->closed_at?->toDateString()])],
            'payments' => [['Reference', 'Customer', 'Deal', 'Amount', 'Currency', 'Method', 'Purpose', 'Status', 'Provider ref', 'Paid at'],
                Payment::with(['customer:id,name', 'sale:id,reference'])->whereBetween('created_at', [$from, $to])->get()
                    ->map(fn ($p) => [$p->reference, $p->customer?->name, $p->sale?->reference, $p->amount, $p->currency, $p->method, $p->purpose, $p->status, $p->provider_reference, $p->paid_at?->toDateTimeString()])],
            'listings' => [['Reference', 'Title', 'Type', 'Listing', 'Status', 'Price', 'Currency', 'Beds', 'Baths', 'Area m²', 'City', 'Agent', 'Verified', 'Views', 'Listed'],
                Property::with('agent.user:id,name')->get()
                    ->map(fn ($p) => [$p->reference, $p->title, $p->property_type, $p->listing_type, $p->status, $p->price, $p->currency, $p->bedrooms, $p->bathrooms, $p->area_sqm, $p->city, $p->agent?->user?->name, $p->verification_status, $p->views_count, $p->created_at->toDateString()])],
            'agents' => [['Agent', 'Type', 'Deals closed', 'Volume', 'Commission', 'Viewings', 'Active listings'],
                collect($this->agentPerformance($from, $to))->map(fn ($a) => [$a['name'], $a['type'], $a['deals'], $a['volume'], $a['commission'], $a['viewings'], $a['listings']])],
            'commissions' => [['Deal', 'Agent', 'Role', 'Rate %', 'Base', 'Amount', 'Status', 'Paid at', 'Reference'],
                Commission::with(['sale:id,reference', 'agent.user:id,name'])->whereBetween('created_at', [$from, $to])->get()
                    ->map(fn ($c) => [$c->sale?->reference, $c->agent?->user?->name, $c->role, $c->rate, $c->base_amount, $c->amount, $c->status, $c->paid_at?->toDateString(), $c->payment_reference])],
            'customers' => [['Name', 'Email', 'Phone', 'Type', 'Status', 'Source', 'Budget min', 'Budget max', 'Agent', 'Created'],
                Customer::with('assignedAgent.user:id,name')->whereBetween('created_at', [$from, $to])->get()
                    ->map(fn ($c) => [$c->name, $c->email, $c->phone, $c->type, $c->status, $c->source, $c->budget_min, $c->budget_max, $c->assignedAgent?->user?->name, $c->created_at->toDateString()])],
            'viewings' => [['Date', 'Property', 'Customer', 'Agent', 'Type', 'Status', 'Interest', 'Feedback'],
                Viewing::with(['property:id,title', 'customer:id,name', 'agent.user:id,name'])->whereBetween('scheduled_at', [$from, $to])->get()
                    ->map(fn ($v) => [$v->scheduled_at->toDateTimeString(), $v->property?->title, $v->customer?->name, $v->agent?->user?->name, $v->type, $v->status, $v->interest_level, $v->feedback])],
            default => abort(404, 'Unknown report.'),
        };

        $filename = "{$report}-{$from->toDateString()}-to-{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function range(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->subMonths(11)->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        return [$from, $to];
    }

    private function salesByMonth(Carbon $from, Carbon $to): array
    {
        $rows = Sale::where('status', 'completed')->whereBetween('closed_at', [$from, $to])
            ->selectRaw("DATE_FORMAT(closed_at, '%Y-%m') as month, type, COUNT(*) as deals, SUM(COALESCE(agreed_price, offer_amount)) as value")
            ->groupBy('month', 'type')->get();
        $revenue = Payment::where('status', 'completed')->whereBetween('paid_at', [$from, $to])
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as month, SUM(amount) as total")->groupBy('month')->pluck('total', 'month');

        $out = [];
        for ($m = $from->copy()->startOfMonth(); $m <= $to; $m->addMonth()) {
            $key = $m->format('Y-m');
            $out[] = [
                'month' => $m->format('M y'),
                'sales' => (float) $rows->where('month', $key)->where('type', 'sale')->sum('value'),
                'rentals' => (float) $rows->where('month', $key)->where('type', 'rental')->sum('value'),
                'deals' => (int) $rows->where('month', $key)->sum('deals'),
                'revenue' => (float) ($revenue[$key] ?? 0),
            ];
        }

        return $out;
    }

    private function agentPerformance(Carbon $from, Carbon $to): array
    {
        return Agent::with('user:id,name,avatar_path')
            ->withCount([
                'sales as deals' => fn ($q) => $q->where('status', 'completed')->whereBetween('closed_at', [$from, $to]),
                'viewings as viewings' => fn ($q) => $q->where('status', 'completed')->whereBetween('scheduled_at', [$from, $to]),
                'properties as listings' => fn ($q) => $q->whereIn('status', ['available', 'under_offer']),
            ])
            ->withSum(['sales as volume' => fn ($q) => $q->where('status', 'completed')->whereBetween('closed_at', [$from, $to])], 'agreed_price')
            ->withSum(['commissions as commission' => fn ($q) => $q->where('status', '!=', 'cancelled')->whereBetween('created_at', [$from, $to])], 'amount')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->user?->name, 'avatar_url' => $a->user?->avatar_url, 'type' => $a->type,
                'deals' => $a->deals, 'viewings' => $a->viewings, 'listings' => $a->listings,
                'volume' => (float) $a->volume, 'commission' => (float) $a->commission,
            ])
            ->sortByDesc('volume')->values()->all();
    }
}
