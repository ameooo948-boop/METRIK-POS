<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_in_and_stock_out_update_balance_and_append_movements(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Stock Movement Market');
        $product = $this->createProduct($organization, $owner, 'STOCK-001', '10.000');

        $this->actingAs($owner)->post(route('inventory.adjustments.store'), [
            'product_id' => $product->getKey(), 'type' => 'stock_in', 'quantity' => '5.250', 'reason' => 'استلام بضاعة',
        ])->assertRedirect(route('products.index'));

        $this->actingAs($owner)->post(route('inventory.adjustments.store'), [
            'product_id' => $product->getKey(), 'type' => 'stock_out', 'quantity' => '3.125', 'reason' => 'تالف',
        ])->assertRedirect(route('products.index'));

        $balance = InventoryBalance::query()
            ->where('organization_id', $organization->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('product_id', $product->getKey())
            ->firstOrFail();

        $this->assertSame('12.125', (string) $balance->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->getKey(),
            'movement_type' => 'stock_out',
            'quantity_delta' => '-3.125',
            'balance_after' => '12.125',
        ]);
        $this->assertDatabaseCount('inventory_movements', 3);
    }

    public function test_stock_out_cannot_make_branch_balance_negative(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Negative Stock Market');
        $product = $this->createProduct($organization, $owner, 'STOCK-002', '2.000');

        $this->actingAs($owner)
            ->from(route('products.index'))
            ->post(route('inventory.adjustments.store'), [
                'product_id' => $product->getKey(), 'type' => 'stock_out', 'quantity' => '2.001', 'reason' => 'اختبار',
            ])
            ->assertRedirect(route('products.index'))
            ->assertSessionHasErrors('quantity');

        $balance = InventoryBalance::query()
            ->where('organization_id', $organization->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('product_id', $product->getKey())
            ->firstOrFail();

        $this->assertSame('2.000', (string) $balance->quantity);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    private function createOrganizationFor(string $name): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'trial', 'timezone' => 'Africa/Cairo', 'currency' => 'EGP',
        ]);
        $branch = $organization->branches()->create([
            'name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'status' => 'active',
        ]);
        $organization->users()->attach($user->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($user->getKey());

        return [$user, $organization, $branch];
    }

    private function createProduct(Organization $organization, User $owner, string $sku, string $quantity): Product
    {
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(),
            'base_unit_id' => (int) \App\Models\Unit::query()->where('code', 'piece')->value('id'),
            'created_by' => $owner->getKey(),
            'sku' => $sku, 'name' => 'منتج اختبار',
            'purchase_price' => '5.00', 'selling_price' => '8.00',
            'low_stock_threshold' => '1.000', 'is_active' => true,
        ]);

        $branch = $organization->branches()->firstOrFail();
        \App\Models\ProductUnitConversion::query()->create([
            'product_id' => $product->getKey(), 'unit_id' => $product->base_unit_id, 'factor_to_base' => '1.000',
        ]);
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => $quantity,
        ]);
        \App\Models\InventoryMovement::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'created_by' => $owner->getKey(),
            'movement_type' => 'opening', 'quantity_delta' => $quantity, 'balance_after' => $quantity,
            'source' => 'test', 'reason' => 'رصيد افتتاحي للاختبار',
        ]);

        return $product;
    }
}
