<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Safaricom/Vodacom M-Pesa (Daraja) STK push. Sends the prompt to the
 * payer's phone; the provider later calls POST /api/payments/mpesa/callback
 * which completes or fails the payment. Configure in config/rems.php.
 */
class MpesaGateway implements PaymentGateway
{
    public function initiate(Payment $payment): array
    {
        $cfg = config('rems.payments.mpesa');
        if (! $cfg['consumer_key'] || ! $cfg['consumer_secret'] || ! $cfg['shortcode']) {
            throw new RuntimeException('M-Pesa is not configured. Set MPESA_* in .env or use PAYMENT_DRIVER=simulated.');
        }

        $token = Http::withBasicAuth($cfg['consumer_key'], $cfg['consumer_secret'])
            ->get($cfg['base_url'].'/oauth/v1/generate?grant_type=client_credentials')
            ->throw()->json('access_token');

        $timestamp = now()->format('YmdHis');
        $response = Http::withToken($token)->post($cfg['base_url'].'/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => $cfg['shortcode'],
            'Password' => base64_encode($cfg['shortcode'].$cfg['passkey'].$timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (int) ceil((float) $payment->amount),
            'PartyA' => preg_replace('/\D/', '', (string) $payment->payer_phone),
            'PartyB' => $cfg['shortcode'],
            'PhoneNumber' => preg_replace('/\D/', '', (string) $payment->payer_phone),
            'CallBackURL' => $cfg['callback_url'],
            'AccountReference' => $payment->reference,
            'TransactionDesc' => config('app.name').' '.$payment->purpose,
        ])->throw()->json();

        $payment->update([
            'status' => 'processing',
            'provider' => 'mpesa',
            'provider_reference' => $response['CheckoutRequestID'] ?? null,
            'meta' => ['stk' => $response],
        ]);

        return ['status' => 'processing', 'message' => $response['CustomerMessage'] ?? 'Check your phone to approve the payment.', 'redirect_url' => null];
    }
}
