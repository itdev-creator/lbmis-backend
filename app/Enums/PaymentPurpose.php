<?php

namespace App\Enums;

enum PaymentPurpose: string
{
    case Subscription = 'subscription';  // first payment for a plan, saves the card
    case Renewal = 'renewal';            // automatic monthly/yearly charge with the saved card
    case BrandingFee = 'branding_fee';   // reserved for the monthly branding-fee invoices

    public function label(): string
    {
        return match ($this) {
            self::Subscription => 'New subscription',
            self::Renewal => 'Renewal',
            self::BrandingFee => 'Branding fee',
        };
    }
}
