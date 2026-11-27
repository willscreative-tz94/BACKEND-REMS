<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\AuditLogger;
use App\Services\Payments\PaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Online payments (mobile money / card via the configured gateway) and manually recorded receipts. */
class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::visible()->with(['sale:id,reference,property_id', 'sale.property:id,title,reference', 'customer:id,name'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->method, fn ($q, $v) => $q->where('method', $v))
            ->when($request->sale_id, fn ($q, $v) => $q->where('sale_id', $v))
            ->when($request->q, fn ($q, $v) => $q->where(fn ($w) => $w->where('reference', 'like', "%{$v}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$v}%"))))
            ->latest();

        $summary = [
            'completed' => (float) Payment::visible()->where('status', 'completed')->sum('amount'),
            'pending' => (float) Payment::visible()->whereIn('status', ['pending', 'processing'])->sum('amount'),
            'count' => Payment::visible()->count(),
        ];

        return response()->json([...$query->paginate($this->perPage())->toArray(), 'summary' => $summary]);
    }

    /** Pay online against a deal: creates the payment and asks the gateway to collect it. */
    public function initiate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sale_id' => ['required', Rule::exists('sales', 'id')->where('organization_id', tenant()->id())],
            'amount' => 'required|numeric|min:1',
            'method' => 'required|in:mobile_money,card',
            'purpose' => 'required|in:booking_fee,deposit,installment,full_payment,rent,other',
            'payer_phone' => 'required_if:method,mobile_money|nullable|string|max:32',
        ]);

        $sale = Sale::visible()->findOrFail($data['sale_id']);
        abort_if(in_array($sale->status, ['cancelled', 'rejected'], true), 422, 'This deal is closed.');
        abort_if($data['amount'] > $sale->balance + 0.01 && $sale->balance > 0, 422, 'Amount exceeds the outstanding balance.');

        $payment = Payment::create([
            ...$data,
            'customer_id' => $sale->customer_id,
            'currency' => $sale->currency,
            'status' => 'pending',
            'recorded_by' => auth()->id(),
        ]);

        try {
            $result = PaymentService::gateway()->initiate($payment);
        } catch (Throwable $e) {
            Log::error('Payment initiation failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);
            $payment->update(['status' => 'failed', 'meta' => ['error' => $e->getMessage()]]);

            return response()->json(['message' => 'The payment could not be started: '.$e->getMessage(), 'data' => $payment], 422);
        }

        return response()->json(['data' => $payment->fresh(), 'gateway' => $result], 201);
    }

    /** Simulated gateway: the payer approves the prompt. (Real gateways complete via their callback.) */
    public function confirm(Payment $payment): JsonResponse
    {
        abort_unless(Payment::visible()->whereKey($payment->id)->exists(), 404);
        abort_unless($payment->provider === 'simulated' && $payment->status === 'processing', 422, 'Only a pending simulated payment can be confirmed here.');

        return response()->json(['data' => PaymentService::complete($payment)]);
    }

    /** Staff record an offline payment (cash, bank transfer, cheque). */
    public function store(Request $request): JsonResponse
    {
        $this->requireRole('owner', 'admin', 'broker', 'agent');
        $data = $request->validate([
            'sale_id' => ['required', Rule::exists('sales', 'id')->where('organization_id', tenant()->id())],
            'amount' => 'required|numeric|min:1',
            'method' => 'required|in:mobile_money,card,bank_transfer,cash,cheque',
            'purpose' => 'required|in:booking_fee,deposit,installment,full_payment,rent,other',
            'provider_reference' => 'nullable|string|max:120',
            'paid_at' => 'nullable|date',
        ]);
        $sale = Sale::findOrFail($data['sale_id']);
        $this->ensureCanManage($sale->agent_id);

        $payment = Payment::create([
            ...$data, 'customer_id' => $sale->customer_id, 'currency' => $sale->currency, 'provider' => 'manual',
            'status' => 'pending', 'recorded_by' => auth()->id(),
        ]);
        $payment = PaymentService::complete($payment, $data['provider_reference'] ?? null);
        if (! empty($data['paid_at'])) {
            $payment->update(['paid_at' => $data['paid_at']]);
        }

        return response()->json(['data' => $payment], 201);
    }

    public function refund(Payment $payment): JsonResponse
    {
        $this->requireRole('owner', 'admin');
        abort_unless($payment->status === 'completed', 422, 'Only completed payments can be refunded.');
        $payment->update(['status' => 'refunded']);
        AuditLogger::log('payment.refunded', $payment, "Refunded {$payment->reference}");

        return response()->json(['data' => $payment]);
    }

    public function receipt(Payment $payment): Response
    {
        abort_unless(Payment::visible()->whereKey($payment->id)->exists(), 404);
        abort_unless($payment->status === 'completed', 422, 'Receipts are issued for completed payments only.');
        $payment->load(['sale.property', 'customer']);
        $org = tenant()->organization()->load('brand');

        return Pdf::loadView('pdf.receipt', ['payment' => $payment, 'org' => $org, 'brand' => $org->brandPayload()])
            ->download("receipt-{$payment->reference}.pdf");
    }

    /** M-Pesa STK callback (public, no tenant header): find the payment by CheckoutRequestID. */
    public function mpesaCallback(Request $request): JsonResponse
    {
        $body = $request->input('Body.stkCallback', []);
        $checkoutId = $body['CheckoutRequestID'] ?? null;

        $payment = $checkoutId ? tenant()->withoutTenancy(fn () => Payment::where('provider_reference', $checkoutId)->first()) : null;
        if (! $payment) {
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Ignored']);
        }

        tenant()->run($payment->organization, function () use ($payment, $body) {
            $payment = Payment::find($payment->id);
            if ((int) ($body['ResultCode'] ?? 1) === 0) {
                $receipt = collect($body['CallbackMetadata']['Item'] ?? [])->firstWhere('Name', 'MpesaReceiptNumber')['Value'] ?? null;
                $payment->update(['meta' => array_merge($payment->meta ?? [], ['callback' => $body])]);
                PaymentService::complete($payment, $receipt);
            } else {
                $payment->update(['status' => 'failed', 'meta' => array_merge($payment->meta ?? [], ['callback' => $body])]);
            }
        });

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
