<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Sale;

/**
 * Commission on a completed deal: the selling agent earns their rate on
 * the agreed price; when they sit under a broker, the broker's split of
 * that commission is booked as a separate broker_override line.
 */
class CommissionService
{
    public static function generateForSale(Sale $sale): void
    {
        if (! $sale->agent || $sale->commissions()->exists()) {
            return;
        }

        $base = (float) ($sale->agreed_price ?? $sale->offer_amount);
        // Rentals: commission is charged on one month's rent per convention.
        $agent = $sale->agent;
        $rate = (float) ($agent->commission_rate ?: tenant()->organization()?->default_commission_rate ?: 3);
        $gross = round($base * $rate / 100, 2);

        $brokerShare = 0.0;
        if ($agent->broker_id && $agent->broker) {
            $brokerShare = round($gross * (float) $agent->broker_split / 100, 2);
            Commission::create([
                'sale_id' => $sale->id,
                'agent_id' => $agent->broker_id,
                'role' => 'broker_override',
                'rate' => round($rate * (float) $agent->broker_split / 100, 2),
                'base_amount' => $base,
                'amount' => $brokerShare,
                'currency' => $sale->currency,
                'notes' => "Broker split ({$agent->broker_split}%) of {$agent->user?->name}'s commission",
            ]);
        }

        $commission = Commission::create([
            'sale_id' => $sale->id,
            'agent_id' => $agent->id,
            'role' => 'selling_agent',
            'rate' => $rate,
            'base_amount' => $base,
            'amount' => $gross - $brokerShare,
            'currency' => $sale->currency,
        ]);

        NotificationService::toAgent($agent, 'commission.created', 'Commission earned on '.$sale->reference,
            number_format((float) $commission->amount, 2).' '.$sale->currency.' is pending approval.', '/commissions');
    }
}
