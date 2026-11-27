<?php

namespace App\Services\Payments;

use App\Models\Payment;

interface PaymentGateway
{
    /**
     * Start collecting $payment online. Returns what the client needs next:
     * ['status' => 'processing'|'completed'|'failed', 'message' => ..., 'redirect_url' => ?string].
     */
    public function initiate(Payment $payment): array;
}
