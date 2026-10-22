<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Organization-wide, tax-exclusive sales tax settings.
 * Rates are stored as decimal percentages (for example 14.00) and converted to
 * basis points so each line's tax can be calculated deterministically in cents.
 */
final class OrganizationTaxSettings
{
    public static function rateBasisPoints(Organization $organization): int
    {
        $settings = is_array($organization->settings) ? $organization->settings : [];
        $tax = is_array($settings['tax'] ?? null) ? $settings['tax'] : [];

        if (! (bool) ($tax['enabled'] ?? false)) {
            return 0;
        }

        try {
            $rateCents = DecimalMoney::toCents((string) ($tax['rate_percent'] ?? '0.00'));
        } catch (\Throwable) {
            return 0;
        }

        return max(0, min(10_000, $rateCents));
    }

    public static function ratePercent(int $rateBasisPoints): string
    {
        return DecimalMoney::fromCents(max(0, min(10_000, $rateBasisPoints)));
    }

    public static function taxCents(int $taxableAmountCents, int $rateBasisPoints): int
    {
        if ($taxableAmountCents <= 0 || $rateBasisPoints <= 0) {
            return 0;
        }

        // Half-up rounding at each line, consistently applied in online and offline POS.
        return intdiv(($taxableAmountCents * min(10_000, $rateBasisPoints)) + 5_000, 10_000);
    }
}
