<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * Development/demo gateway: leaves the payment "processing" so the UI can
 * show the confirm step, then POST /payments/{id}/confirm completes it —
 * the same lifecycle a real mobile-money callback follows.
 */
class SimulatedGateway implements PaymentGateway
{
    public function initiate(Payment $payment): array
    {
        $payment->update([
            'status' => 'processing',
            'provider' => 'simulated',
            'provider_reference' => 'SIM'.Str::upper(Str::random(10)),
        ]);

        return [
            'status' => 'processing',
            'message' => $payment->method === 'mobile_money'
                ? "A payment prompt was sent to {$payment->payer_phone}. Approve it to finish (simulated)."
                : 'Card authorisation started (simulated). Confirm to complete.',
            'redirect_url' => null,
        ];
    }
}
