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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LowStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_low_stock_based_on_branch_override_or_product_default(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Low Stock Main');
        $low = $this->createProduct($organization, $branch, 'LOW-001', 'صنف منخفض', '2.000', '5.000');
        $enough = $this->createProduct($organization, $branch, 'LOW-002', 'صنف كافٍ', '4.000', '5.000');
        $disabled = $this->createProduct($organization, $branch, 'LOW-003', 'تنبيه معطل', '0.000', '0.000');

        InventoryBalance::query()->where('product_id', $enough->getKey())->where('branch_id', $branch->getKey())->update(['reorder_threshold' => '3.000']);
        InventoryBalance::query()->where('product_id', $disabled->getKey())->where('branch_id', $branch->getKey())->update(['reorder_threshold' => '0.000']);

        $this->actingAs($owner)->get(route('inventory.low-stock.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/LowStock')
                ->where('lowStockCount', 1)
                ->has('products.data', 3));

        $this->actingAs($owner)->get(route('inventory.low-stock.index', ['low_stock' => '1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/LowStock')
                ->where('lowStockCount', 1)
                ->has('products.data', 1)
                ->where('products.data.0.id', $low->getKey()));
    }

    public function test_owner_can_set_branch_threshold_without_changing_product_default_or_stock(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Low Stock Threshold Update');
        $otherBranch = $organization->branches()->create([
            'name' => 'فرع ثانٍ', 'code' => 'BR-02', 'is_main' => false, 'status' => 'active',
        ]);
        $product = $this->createProduct($organization, $branch, 'LOW-010', 'زيت', '4.000', '3.000');
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $otherBranch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => '8.000', 'reorder_threshold' => null,
        ]);

        $this->actingAs($owner)->patch(route('inventory.low-stock.threshold.update', $product->getKey()), [
            'reorder_threshold' => '5.125',
        ])->assertRedirect(route('inventory.low-stock.index'));

        $this->assertSame('3.000', (string) $product->fresh()->low_stock_threshold);
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(), 'branch_id' => $otherBranch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => '8.000', 'reorder_threshold' => null,
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => '4.000',
            'reorder_threshold' => '5.125',
        ]);

        $this->actingAs($owner)->get(route('inventory.low-stock.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lowStockCount', 1)
                ->where('products.data.0.effectiveThreshold', '5.125')
                ->where('products.data.0.isLowStock', true));

        $this->actingAs($owner)->get(route('products.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Index')
                ->where('products.data.0.lowStockThreshold', '5.125'));
    }

    public function test_blank_branch_threshold_restores_product_default(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Low Stock Reset');
        $product = $this->createProduct($organization, $branch, 'LOW-020', 'سكر', '4.000', '3.000');
        InventoryBalance::query()->where('product_id', $product->getKey())->where('branch_id', $branch->getKey())->update(['reorder_threshold' => '7.000']);

        $this->actingAs($owner)->patch(route('inventory.low-stock.threshold.update', $product->getKey()), [
            'reorder_threshold' => null,
        ])->assertRedirect(route('inventory.low-stock.index'));

        $this->assertDatabaseHas('inventory_balances', [
            'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(), 'reorder_threshold' => null,
        ]);
        $this->actingAs($owner)->get(route('inventory.low-stock.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data.0.effectiveThreshold', '3.000')
                ->where('products.data.0.isLowStock', false));
    }

    public function test_threshold_cannot_be_negative_or_have_more_than_three_decimal_places(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Low Stock Invalid Threshold');
        $product = $this->createProduct($organization, $branch, 'LOW-030', 'أرز', '4.000', '3.000');

        $this->actingAs($owner)->from(route('inventory.low-stock.index'))
            ->patch(route('inventory.low-stock.threshold.update', $product->getKey()), ['reorder_threshold' => '-1'])
            ->assertRedirect(route('inventory.low-stock.index'))
            ->assertSessionHasErrors('reorder_threshold');

        $this->actingAs($owner)->from(route('inventory.low-stock.index'))
            ->patch(route('inventory.low-stock.threshold.update', $product->getKey()), ['reorder_threshold' => '1.1234'])
            ->assertRedirect(route('inventory.low-stock.index'))
            ->assertSessionHasErrors('reorder_threshold');

        $this->assertDatabaseHas('inventory_balances', [
            'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(), 'reorder_threshold' => null,
        ]);
    }

    public function test_cashier_cannot_view_or_update_reorder_thresholds(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Low Stock Permissions');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $branch->users()->attach($cashier->getKey());
        $product = $this->createProduct($organization, $branch, 'LOW-040', 'مكرونة', '4.000', '2.000');

        $this->actingAs($cashier)->get(route('inventory.low-stock.index'))->assertForbidden();
        $this->actingAs($cashier)->patch(route('inventory.low-stock.threshold.update', $product->getKey()), ['reorder_threshold' => '6.000'])->assertForbidden();
        $this->assertDatabaseHas('inventory_balances', [
            'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(), 'reorder_threshold' => null,
        ]);
    }

    public function test_product_from_another_organization_cannot_be_updated(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Low Stock Tenant A');
        [, $otherOrganization, $otherBranch] = $this->createOrganizationFor('Low Stock Tenant B');
        $foreignProduct = $this->createProduct($otherOrganization, $otherBranch, 'FOREIGN-LOW', 'منتج أجنبي', '2.000', '1.000');

        $this->actingAs($owner)->patch(route('inventory.low-stock.threshold.update', $foreignProduct->getKey()), [
            'reorder_threshold' => '10.000',
        ])->assertNotFound();

        $this->assertDatabaseHas('inventory_balances', [
            'branch_id' => $otherBranch->getKey(), 'product_id' => $foreignProduct->getKey(), 'reorder_threshold' => null,
        ]);
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

    private function createProduct(Organization $organization, Branch $branch, string $sku, string $name, string $quantity, string $threshold): Product
    {
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(),
            'base_unit_id' => (int) Unit::query()->where('code', 'piece')->value('id'),
            'sku' => $sku,
            'name' => $name,
            'purchase_price' => '5.00',
            'selling_price' => '8.00',
            'low_stock_threshold' => $threshold,
            'is_active' => true,
        ]);
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => $quantity,
            'reorder_threshold' => null,
        ]);

        return $product;
    }
}
