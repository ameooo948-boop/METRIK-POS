<?php

namespace App\Services\POS;

use App\Models\Promotion;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Pricing engine for server-authoritative promotions and cashier discounts.
 * All calculations use integer cents and milli-units; client totals are never trusted.
 */
final class PromotionPricing
{
    /**
     * @param list<array<string, mixed>> $items
     * @param Collection<int, Promotion> $promotions
     * @return array{items:list<array<string,mixed>>,subtotalCents:int,promotionDiscountCents:int,manualDiscountCents:int,discountTotalCents:int,totalCents:int}
     */
    public function apply(
        array $items,
        Collection $promotions,
        int $organizationId,
        ?string $promotionCode,
        string $manualDiscountPercent,
        int $manualDiscountLimitPercent,
    ): array {
        $manualBasisPoints = (int) round(((float) $manualDiscountPercent) * 100);
        if ($manualBasisPoints < 0 || $manualBasisPoints > ($manualDiscountLimitPercent * 100)) {
            throw ValidationException::withMessages([
                'manual_discount_percent' => 'نسبة الخصم اليدوي تتجاوز الحد المسموح به لدورك ('.$manualDiscountLimitPercent.'%).',
            ]);
        }

        foreach ($items as &$item) {
            $item['promotion_discount_cents'] = 0;
            $item['manual_discount_cents'] = 0;
            $item['discount_cents'] = 0;
            $item['net_line_total_cents'] = (int) $item['line_total_cents'];
            $item['promotion_id'] = null;
            $item['promotion_name'] = null;
        }
        unset($item);

        $code = strtoupper(trim((string) $promotionCode));
        $orderedPromotions = $promotions->sort(function (Promotion $left, Promotion $right) use ($code): int {
            $leftCouponFirst = $code !== '' && strtoupper((string) $left->code) === $code ? 0 : 1;
            $rightCouponFirst = $code !== '' && strtoupper((string) $right->code) === $code ? 0 : 1;

            return ($leftCouponFirst <=> $rightCouponFirst)
                ?: ((int) $left->priority <=> (int) $right->priority)
                ?: ((int) $left->getKey() <=> (int) $right->getKey());
        });

        $couponWasApplied = false;
        foreach ($orderedPromotions as $promotion) {
            $promoCode = strtoupper(trim((string) $promotion->code));
            if ($promoCode !== '' && ($code === '' || $promoCode !== $code)) {
                continue;
            }

            $productIds = $promotion->products
                ->filter(static fn ($product): bool => (int) $product->organization_id === $organizationId)
                ->pluck('id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
            if ($productIds === [] || count($productIds) !== $promotion->products->count()) {
                continue;
            }

            $didApply = match ((string) $promotion->type) {
                'buy_x_get_y' => $this->applyBuyXGetY($items, $promotion, $productIds),
                'product_percentage' => $this->applyProductPercentage($items, $promotion, $productIds),
                'bundle_percentage' => $this->applyBundlePercentage($items, $promotion, $productIds),
                default => false,
            };

            if ($didApply && $promoCode !== '') {
                $couponWasApplied = true;
            }
        }

        if ($code !== '' && ! $couponWasApplied) {
            throw ValidationException::withMessages([
                'promotion_code' => 'الكوبون غير صالح أو لا تنطبق شروطه على المنتجات والكميات الموجودة في السلة.',
            ]);
        }

        $subtotalCents = 0;
        $promotionDiscountCents = 0;
        $manualDiscountCents = 0;
        $totalCents = 0;

        foreach ($items as &$item) {
            $gross = (int) $item['line_total_cents'];
            $promoDiscount = min($gross, (int) $item['promotion_discount_cents']);
            $afterPromotion = $gross - $promoDiscount;
            $manual = $manualBasisPoints > 0
                ? min($afterPromotion, intdiv(($afterPromotion * $manualBasisPoints) + 5000, 10000))
                : 0;
            $discount = $promoDiscount + $manual;
            $net = $gross - $discount;

            $item['promotion_discount_cents'] = $promoDiscount;
            $item['manual_discount_cents'] = $manual;
            $item['discount_cents'] = $discount;
            $item['net_line_total_cents'] = $net;
            $subtotalCents += $gross;
            $promotionDiscountCents += $promoDiscount;
            $manualDiscountCents += $manual;
            $totalCents += $net;
        }
        unset($item);

        return [
            'items' => $items,
            'subtotalCents' => $subtotalCents,
            'promotionDiscountCents' => $promotionDiscountCents,
            'manualDiscountCents' => $manualDiscountCents,
            'discountTotalCents' => $promotionDiscountCents + $manualDiscountCents,
            'totalCents' => $totalCents,
        ];
    }

    /** @param list<array<string,mixed>> $items */
    private function applyBuyXGetY(array &$items, Promotion $promotion, array $productIds): bool
    {
        if (count($productIds) !== 1 || (int) $promotion->buy_quantity < 1 || (int) $promotion->free_quantity < 1) {
            return false;
        }

        $matched = false;
        foreach ($items as &$item) {
            if ((int) $item['product']->getKey() !== $productIds[0]
                || (int) ($item['unit']['id'] ?? 0) !== (int) $promotion->unit_id
                || $item['promotion_id'] !== null) {
                continue;
            }

            $quantityMilli = (int) $item['quantity_milli'];
            $cycleMilli = ((int) $promotion->buy_quantity + (int) $promotion->free_quantity) * 1000;
            $cycles = intdiv($quantityMilli, $cycleMilli);
            $freeMilli = $cycles * (int) $promotion->free_quantity * 1000;
            if ($freeMilli <= 0) {
                continue;
            }

            $discount = intdiv(($freeMilli * (int) $item['unit_price_cents']) + 500, 1000);
            $discount = min((int) $item['line_total_cents'], $discount);
            if ($discount <= 0) {
                continue;
            }

            $item['promotion_discount_cents'] = $discount;
            $item['promotion_id'] = (int) $promotion->getKey();
            $item['promotion_name'] = (string) $promotion->name;
            $matched = true;
        }
        unset($item);

        return $matched;
    }

    /** @param list<array<string,mixed>> $items */
    private function applyProductPercentage(array &$items, Promotion $promotion, array $productIds): bool
    {
        $basisPoints = (int) round(((float) $promotion->discount_percent) * 100);
        if ($basisPoints <= 0 || $basisPoints > 9000) {
            return false;
        }

        $matched = false;
        foreach ($items as &$item) {
            if (! in_array((int) $item['product']->getKey(), $productIds, true)
                || ($promotion->unit_id !== null && (int) ($item['unit']['id'] ?? 0) !== (int) $promotion->unit_id)
                || $item['promotion_id'] !== null) {
                continue;
            }

            $discount = intdiv(((int) $item['line_total_cents'] * $basisPoints) + 5000, 10000);
            $discount = min((int) $item['line_total_cents'], $discount);
            if ($discount <= 0) {
                continue;
            }

            $item['promotion_discount_cents'] = $discount;
            $item['promotion_id'] = (int) $promotion->getKey();
            $item['promotion_name'] = (string) $promotion->name;
            $matched = true;
        }
        unset($item);

        return $matched;
    }

    /** @param list<array<string,mixed>> $items */
    private function applyBundlePercentage(array &$items, Promotion $promotion, array $productIds): bool
    {
        if (count($productIds) < 2) {
            return false;
        }
        $basisPoints = (int) round(((float) $promotion->discount_percent) * 100);
        if ($basisPoints <= 0 || $basisPoints > 9000) {
            return false;
        }

        $presentIds = [];
        foreach ($items as $item) {
            if ((int) $item['quantity_milli'] > 0) {
                $presentIds[] = (int) $item['product']->getKey();
            }
        }
        if (array_diff($productIds, array_unique($presentIds)) !== []) {
            return false;
        }

        foreach ($items as $item) {
            if (in_array((int) $item['product']->getKey(), $productIds, true) && $item['promotion_id'] !== null) {
                return false;
            }
        }

        $matched = false;
        foreach ($items as &$item) {
            if (! in_array((int) $item['product']->getKey(), $productIds, true)) {
                continue;
            }
            $discount = intdiv(((int) $item['line_total_cents'] * $basisPoints) + 5000, 10000);
            $discount = min((int) $item['line_total_cents'], $discount);
            if ($discount <= 0) {
                continue;
            }
            $item['promotion_discount_cents'] = $discount;
            $item['promotion_id'] = (int) $promotion->getKey();
            $item['promotion_name'] = (string) $promotion->name;
            $matched = true;
        }
        unset($item);

        return $matched;
    }
}
