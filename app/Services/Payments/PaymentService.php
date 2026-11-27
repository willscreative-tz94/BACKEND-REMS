<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use InvalidArgumentException;

class PaymentService
{
    public static function gateway(): PaymentGateway
    {
        return match (config('rems.payments.driver')) {
            'mpesa' => new MpesaGateway,
            'simulated' => new SimulatedGateway,
            default => throw new InvalidArgumentException('Unknown payment driver '.config('rems.payments.driver')),
        };
    }

    /** Mark a payment completed and move the deal forward; idempotent. */
    public static function complete(Payment $payment, ?string $providerReference = null): Payment
    {
        if ($payment->status === 'completed') {
            return $payment;
        }

        $payment->update([
            'status' => 'completed',
            'paid_at' => now(),
            'provider_reference' => $providerReference ?? $payment->provider_reference,
        ]);

        $sale = $payment->sale;
        if ($sale && in_array($sale->status, ['offer', 'negotiating'], true) && in_array($payment->purpose, ['booking_fee', 'deposit'], true)) {
            $sale->update(['status' => 'accepted', 'accepted_at' => now(), 'agreed_price' => $sale->agreed_price ?? $sale->offer_amount]);
        }

        AuditLogger::log('payment.completed', $payment, "Payment {$payment->reference} of {$payment->currency} ".number_format((float) $payment->amount, 2).' received');
        NotificationService::toCustomer($payment->customer, 'payment.completed', 'Payment received',
            "We received {$payment->currency} ".number_format((float) $payment->amount, 2)." ({$payment->reference}). Thank you.", '/payments');
        NotificationService::toAgent($sale?->agent, 'payment.completed', "Payment received on {$sale?->reference}",
            "{$payment->customer?->name} paid {$payment->currency} ".number_format((float) $payment->amount, 2).'.', $sale ? "/sales/{$sale->id}" : '/payments');

        return $payment->fresh();
    }
}
