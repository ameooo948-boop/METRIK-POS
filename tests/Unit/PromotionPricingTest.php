<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Promotion;
use App\Services\POS\PromotionPricing;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PromotionPricingTest extends TestCase
{
    public function test_buy_two_get_one_free_applies_the_free_quantity_as_a_line_discount(): void
    {
        $product = $this->product(1, 7);
        $promotion = $this->promotion(10, [
            'organization_id' => 7,
            'name' => 'اشتر قطعتين والثالثة مجانًا',
            'type' => 'buy_x_get_y',
            'unit_id' => 1,
            'buy_quantity' => 2,
            'free_quantity' => 1,
            'priority' => 10,
        ], [$product]);

        $result = app(PromotionPricing::class)->apply(
            [$this->item($product, 3000, 1000, 3000)],
            collect([$promotion]),
            7,
            null,
            '0.00',
            30,
        );

        $this->assertSame(1000, $result['promotionDiscountCents']);
        $this->assertSame(2000, $result['totalCents']);
        $this->assertSame(10, $result['items'][0]['promotion_id']);
        $this->assertSame('اشتر قطعتين والثالثة مجانًا', $result['items'][0]['promotion_name']);
    }

    public function test_product_percentage_coupon_is_applied_and_snapshotted_on_the_line(): void
    {
        $product = $this->product(2, 7);
        $promotion = $this->promotion(11, [
            'organization_id' => 7,
            'name' => 'خصم 10 بالمئة',
            'code' => 'SAVE10',
            'type' => 'product_percentage',
            'discount_percent' => '10.00',
            'priority' => 10,
        ], [$product]);

        $result = app(PromotionPricing::class)->apply(
            [$this->item($product, 1000, 2500, 2500)],
            collect([$promotion]),
            7,
            'SAVE10',
            '0.00',
            30,
        );

        $this->assertSame(250, $result['promotionDiscountCents']);
        $this->assertSame(2250, $result['totalCents']);
        $this->assertSame(11, $result['items'][0]['promotion_id']);
    }

    public function test_bundle_discount_requires_all_products_and_discounts_the_selected_group(): void
    {
        $first = $this->product(3, 7);
        $second = $this->product(4, 7);
        $promotion = $this->promotion(12, [
            'organization_id' => 7,
            'name' => 'خصم مجموعة',
            'type' => 'bundle_percentage',
            'discount_percent' => '10.00',
            'priority' => 10,
        ], [$first, $second]);
        $engine = app(PromotionPricing::class);

        $notComplete = $engine->apply(
            [$this->item($first, 1000, 1000, 1000)],
            collect([$promotion]), 7, null, '0.00', 30,
        );
        $this->assertSame(0, $notComplete['promotionDiscountCents']);
        $this->assertSame(1000, $notComplete['totalCents']);

        $complete = $engine->apply(
            [$this->item($first, 1000, 1000, 1000), $this->item($second, 1000, 2000, 2000)],
            collect([$promotion]), 7, null, '0.00', 30,
        );
        $this->assertSame(300, $complete['promotionDiscountCents']);
        $this->assertSame(2700, $complete['totalCents']);
    }

    public function test_manual_discount_cannot_exceed_role_limit(): void
    {
        $this->expectException(ValidationException::class);

        $product = $this->product(5, 7);
        app(PromotionPricing::class)->apply(
            [$this->item($product, 1000, 1000, 1000)],
            collect(), 7, null, '30.01', 30,
        );
    }

    private function product(int $id, int $organizationId): Product
    {
        $product = new Product();
        $product->setAttribute('id', $id);
        $product->setAttribute('organization_id', $organizationId);
        $product->setAttribute('name', 'Product '.$id);

        return $product;
    }

    /** @param array<string,mixed> $attributes @param list<Product> $products */
    private function promotion(int $id, array $attributes, array $products): Promotion
    {
        $promotion = new Promotion();
        $promotion->setAttribute('id', $id);
        foreach ($attributes as $key => $value) {
            $promotion->setAttribute($key, $value);
        }
        $promotion->setRelation('products', collect($products));

        return $promotion;
    }

    /** @return array<string,mixed> */
    private function item(Product $product, int $quantityMilli, int $unitPriceCents, int $lineTotalCents): array
    {
        return [
            'product' => $product,
            'unit' => ['id' => 1],
            'quantity_milli' => $quantityMilli,
            'unit_price_cents' => $unitPriceCents,
            'line_total_cents' => $lineTotalCents,
            'promotion_id' => null,
            'promotion_name' => null,
        ];
    }
}
