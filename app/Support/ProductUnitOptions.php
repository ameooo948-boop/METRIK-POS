<?php

namespace App\Support;

use App\Models\Product;

/**
 * Builds authoritative unit options for a product. Stock quantities are always
 * stored in base units; factor_to_base converts the selected unit to that base.
 */
final class ProductUnitOptions
{
    /** @return array{id:int,persistedId:?int,unitName:string,factorToBase:string,factorMilli:int,sellingPrice:string,purchasePrice:string,isBase:bool}|null */
    public static function resolve(Product $product, ?int $unitId): ?array
    {
        $baseUnitId = (int) $product->base_unit_id;
        $resolvedId = $unitId ?? $baseUnitId;

        if ($resolvedId === $baseUnitId) {
            return [
                'id' => $baseUnitId,
                'persistedId' => null,
                'unitName' => (string) ($product->baseUnit?->name_ar ?? $product->baseUnit?->name_en ?? 'وحدة أساسية'),
                'factorToBase' => '1.000',
                'factorMilli' => 1000,
                'sellingPrice' => DecimalMoney::fromCents(self::moneyCents($product->selling_price)),
                'purchasePrice' => DecimalMoney::fromCents(self::moneyCents($product->purchase_price)),
                'isBase' => true,
            ];
        }

        $conversion = $product->unitConversions->first(
            static fn ($candidate): bool => (int) $candidate->unit_id === $resolvedId && (bool) ($candidate->is_active ?? true),
        );

        if ($conversion === null || $conversion->unit === null) {
            return null;
        }

        $factor = (string) $conversion->factor_to_base;
        try {
            $factorMilli = DecimalQuantity::toMilli($factor);
        } catch (\Throwable) {
            return null;
        }

        if ($factorMilli <= 0 || $factorMilli > 100_000_000) {
            return null;
        }

        $baseSellingCents = self::moneyCents($product->selling_price);
        $basePurchaseCents = self::moneyCents($product->purchase_price);
        $sellingCents = self::hasMoneyValue($conversion->selling_price)
            ? self::moneyCents($conversion->selling_price)
            : self::scaleMoney($baseSellingCents, $factorMilli);
        $purchaseCents = self::hasMoneyValue($conversion->purchase_price)
            ? self::moneyCents($conversion->purchase_price)
            : self::scaleMoney($basePurchaseCents, $factorMilli);

        return [
            'id' => $resolvedId,
            'persistedId' => $resolvedId,
            'unitName' => (string) ($conversion->unit->name_ar ?? $conversion->unit->name_en ?? 'وحدة'),
            'factorToBase' => $factor,
            'factorMilli' => $factorMilli,
            'sellingPrice' => DecimalMoney::fromCents($sellingCents),
            'purchasePrice' => DecimalMoney::fromCents($purchaseCents),
            'isBase' => false,
        ];
    }

    /** @return list<array{id:int,unitName:string,factorToBase:string,sellingPrice:string,purchasePrice:string,isBase:bool}> */
    public static function options(Product $product): array
    {
        $options = [];
        $baseUnitId = (int) $product->base_unit_id;
        $base = self::resolve($product, $baseUnitId);
        if ($base !== null) {
            $options[] = [
                'id' => $base['id'], 'unitName' => $base['unitName'],
                'factorToBase' => $base['factorToBase'], 'sellingPrice' => $base['sellingPrice'],
                'purchasePrice' => $base['purchasePrice'], 'isBase' => true,
            ];
        }

        foreach ($product->unitConversions->sortBy('unit_id') as $conversion) {
            // Older product creation stored a factor=1 conversion row for the base unit.
            // The base option above is canonical; never expose the legacy row as a duplicate.
            if ((int) $conversion->unit_id === $baseUnitId || ! (bool) ($conversion->is_active ?? true)) {
                continue;
            }
            $option = self::resolve($product, (int) $conversion->unit_id);
            if ($option === null) {
                continue;
            }
            $options[] = [
                'id' => $option['id'], 'unitName' => $option['unitName'],
                'factorToBase' => $option['factorToBase'], 'sellingPrice' => $option['sellingPrice'],
                'purchasePrice' => $option['purchasePrice'], 'isBase' => false,
            ];
        }

        return $options;
    }

    private static function hasMoneyValue(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    private static function moneyCents(mixed $value): int
    {
        if (! self::hasMoneyValue($value)) {
            return 0;
        }

        return DecimalMoney::toCents((string) $value);
    }

    private static function scaleMoney(int $baseCents, int $factorMilli): int
    {
        if ($baseCents <= 0 || $factorMilli <= 0) {
            return 0;
        }

        // Keep intermediate integer multiplication inside the platform range. The
        // POS rejects prices above its allowed ceiling, so a large sentinel is safer
        // than overflowing to a float and silently rounding a financial value.
        $largestSafeBase = intdiv(PHP_INT_MAX - 500, $factorMilli);
        if ($baseCents > $largestSafeBase) {
            return 99_999_999_999_999;
        }

        return intdiv(($baseCents * $factorMilli) + 500, 1000);
    }
}
