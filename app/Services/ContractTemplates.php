<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Property;
use App\Models\Sale;

/** Starting text for each contract type, filled from the deal; editable before sending. */
class ContractTemplates
{
    public static function render(string $type, Property $property, Customer $customer, ?Sale $sale = null, array $extra = []): array
    {
        $org = tenant()->organization();
        $currency = $sale?->currency ?? $property->currency;
        $amount = number_format((float) ($sale?->agreed_price ?? $sale?->offer_amount ?? $property->price), 2);
        $deposit = $sale?->deposit_amount ? number_format((float) $sale->deposit_amount, 2) : null;
        $agent = $sale?->agent?->user?->name ?? $property->agent?->user?->name ?? $org->name;
        $date = now()->format('j F Y');
        $location = collect([$property->address, $property->neighborhood, $property->city, $property->region])->filter()->implode(', ');
        $start = $extra['start_date'] ?? now()->toDateString();
        $end = $extra['end_date'] ?? now()->addYear()->toDateString();

        return match ($type) {
            'lease_agreement' => [
                'title' => "Lease Agreement — {$property->title}",
                'body' => <<<TXT
                THIS LEASE AGREEMENT is made on {$date} between {$org->name} ("the Agent"), acting for the Landlord, and {$customer->name} ("the Tenant").

                1. PREMISES. The Landlord lets to the Tenant the property known as {$property->title} (Ref. {$property->reference}), situated at {$location}.
                2. TERM. The lease runs from {$start} to {$end}, renewable by written agreement of both parties.
                3. RENT. The Tenant shall pay {$currency} {$amount} per month, in advance, on or before the 5th day of each month.
                4. DEPOSIT. A refundable security deposit of {$currency} {$deposit} is payable on signing and is returned within 30 days of handover, less the cost of any damage beyond fair wear and tear.
                5. USE. The premises shall be used for residential purposes only and shall not be sublet without the Landlord's written consent.
                6. MAINTENANCE. The Tenant keeps the premises clean and in good repair; structural repairs remain the Landlord's responsibility.
                7. UTILITIES. Electricity, water and service charges are payable by the Tenant unless agreed otherwise in writing.
                8. TERMINATION. Either party may terminate with two (2) months' written notice. Rent arrears exceeding 30 days entitle the Landlord to terminate immediately.
                9. GOVERNING LAW. This agreement is governed by the laws of {$org->country}.

                Agent: {$agent}
                TXT,
            ],
            'listing_mandate' => [
                'title' => "Listing Mandate — {$property->title}",
                'body' => <<<TXT
                LISTING MANDATE made on {$date} between {$customer->name} ("the Owner") and {$org->name} ("the Agency").

                1. The Owner appoints the Agency to market the property {$property->title} (Ref. {$property->reference}) at {$location} for {$property->listing_type} at an asking price of {$currency} {$amount}.
                2. The mandate is exclusive for a period of 90 days from the date of signing.
                3. The Agency's commission is {$org->default_commission_rate}% of the final sale price (or one month's rent for lettings), payable on completion.
                4. The Owner warrants that they hold good title to the property and will provide the title deed and other documents for verification.
                5. The Agency will advertise the property, arrange viewings, and present all offers to the Owner.

                Listing agent: {$agent}
                TXT,
            ],
            'offer_letter' => [
                'title' => "Letter of Offer — {$property->title}",
                'body' => <<<TXT
                {$date}

                To: {$customer->name}

                RE: OFFER FOR {$property->title} (Ref. {$property->reference}), {$location}

                We are pleased to confirm that the vendor has accepted your offer of {$currency} {$amount} for the above property, subject to:
                  (a) payment of a deposit of {$currency} {$deposit} within 14 days of this letter;
                  (b) execution of a formal Sale Agreement within 30 days;
                  (c) satisfactory verification of title and approvals.

                This offer lapses if the deposit is not received within the period stated.

                Yours faithfully,
                {$agent}, for {$org->name}
                TXT,
            ],
            default => [
                'title' => "Sale Agreement — {$property->title}",
                'body' => <<<TXT
                THIS SALE AGREEMENT is made on {$date} between the Vendor, represented by {$org->name} ("the Agent"), and {$customer->name} ("the Purchaser").

                1. PROPERTY. The Vendor agrees to sell and the Purchaser agrees to buy the property {$property->title} (Ref. {$property->reference}) situated at {$location}, together with all fixtures and fittings.
                2. PURCHASE PRICE. The purchase price is {$currency} {$amount}.
                3. DEPOSIT. A deposit of {$currency} {$deposit} is payable on signing and is held by the Agent as stakeholder.
                4. BALANCE. The balance is payable on or before completion, by bank transfer to the account nominated by the Vendor.
                5. COMPLETION. Completion shall take place within ninety (90) days of signing, on which date vacant possession and the original title documents shall be delivered to the Purchaser.
                6. TITLE. The Vendor warrants good and marketable title free from encumbrances. The Purchaser may carry out an official search before completion.
                7. COSTS. Each party bears its own legal costs. Stamp duty and registration fees are borne by the Purchaser.
                8. DEFAULT. If the Purchaser defaults, the deposit may be forfeited; if the Vendor defaults, the deposit shall be refunded in full.
                9. GOVERNING LAW. This agreement is governed by the laws of {$org->country}.

                Agent: {$agent}
                TXT,
            ],
        };
    }
}
