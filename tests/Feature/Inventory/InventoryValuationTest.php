<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryValuationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_branch_scoped_stock_valuation_totals_and_product_values(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Valuation Report Market');
        $category = $organization->categories()->create(['name' => 'بقالة']);
        $product = $this->createProduct($organization, $owner, $branch, 'VAL-001', 'أرز', '10.000', '2.00', '3.50', $category->getKey());
        $this->createProduct($organization, $owner, $branch, 'VAL-002', 'شاي', '0.000', '4.00', '6.00', $category->getKey());
        $otherBranch = $organization->branches()->create([
            'name' => 'فرع إضافي', 'code' => 'ALT', 'is_main' => false, 'status' => 'active',
        ]);
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $otherBranch->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => '500.000',
        ]);

        $this->actingAs($owner)
            ->get(route('inventory.valuation.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inventory/Valuation')
                ->where('summary.productCount', 2)
                ->where('summary.outOfStockCount', 1)
                ->where('summary.stockCostValue', '20.00')
                ->where('summary.stockRetailValue', '35.00')
                ->where('summary.potentialGrossProfit', '15.00')
                ->where('products.data.0.sku', 'VAL-001')
                ->where('products.data.0.quantity', '10.000')
                ->where('products.data.0.stockCostValue', '20.00')
                ->where('products.data.0.stockRetailValue', '35.00'));
    }

    public function test_valuation_csv_is_scoped_to_the_current_organization_and_current_branch(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Valuation CSV Market');
        [, $otherOrganization, $otherBranch] = $this->createOrganizationFor('Other Valuation CSV Market');
        $this->createProduct($organization, $owner, $branch, 'VAL-EXPORT-001', 'منتج مسموح', '2.000', '5.00', '8.00');
        $otherOwner = $otherOrganization->users()->firstOrFail();
        $this->createProduct($otherOrganization, $otherOwner, $otherBranch, 'PRIVATE-VALUATION-002', 'منتج مؤسسة أخرى', '99.000', '1.00', '2.00');

        $response = $this->actingAs($owner)->get(route('inventory.valuation.export'))->assertOk();
        $contents = $response->streamedContent();

        $this->assertStringContainsString('VAL-EXPORT-001', $contents);
        $this->assertStringContainsString('10.00', $contents);
        $this->assertStringContainsString('16.00', $contents);
        $this->assertStringNotContainsString('PRIVATE-VALUATION-002', $contents);
        $this->assertStringNotContainsString('منتج مؤسسة أخرى', $contents);
    }

    public function test_cashier_cannot_view_or_export_inventory_valuation(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Valuation Permissions Market');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($cashier)->get(route('inventory.valuation.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('inventory.valuation.export'))->assertForbidden();
    }

    private function createOrganizationFor(string $name): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'trial',
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);
        $branch = $organization->branches()->create([
            'name' => 'الفرع الرئيسي',
            'code' => 'MAIN',
            'is_main' => true,
            'status' => 'active',
        ]);
        $organization->users()->attach($user->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($user->getKey());

        return [$user, $organization, $branch];
    }

    private function createProduct(
        Organization $organization,
        User $owner,
        Branch $branch,
        string $sku,
        string $name,
        string $quantity,
        string $purchasePrice,
        string $sellingPrice,
        ?int $categoryId = null,
    ): Product {
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(),
            'category_id' => $categoryId,
            'base_unit_id' => (int) Unit::query()->where('code', 'piece')->value('id'),
            'created_by' => $owner->getKey(),
            'sku' => $sku,
            'name' => $name,
            'purchase_price' => $purchasePrice,
            'selling_price' => $sellingPrice,
            'low_stock_threshold' => '1.000',
            'is_active' => true,
        ]);

        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => $quantity,
        ]);

        return $product;
    }
}
