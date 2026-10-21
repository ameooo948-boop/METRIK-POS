<?php

namespace Tests\Feature\Catalog;

use App\Models\Branch;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_products_scoped_to_their_organization(): void
    {
        [$owner, $organization] = $this->createOrganizationFor('Main Market');
        [$otherOwner, $otherOrganization] = $this->createOrganizationFor('Other Market');
        $unitId = $this->unitId('piece');

        Product::query()->create([
            'organization_id' => $organization->getKey(),
            'base_unit_id' => $unitId,
            'sku' => 'OWN-001',
            'name' => 'منتج مؤسستي',
            'purchase_price' => '5.00',
            'selling_price' => '7.00',
            'low_stock_threshold' => '2.000',
            'is_active' => true,
        ]);
        Product::query()->create([
            'organization_id' => $otherOrganization->getKey(),
            'base_unit_id' => $unitId,
            'sku' => 'OTH-001',
            'name' => 'منتج مؤسسة أخرى',
            'purchase_price' => '1.00',
            'selling_price' => '2.00',
            'low_stock_threshold' => '0.000',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Index')
                ->has('products.data', 1)
                ->where('products.data.0.name', 'منتج مؤسستي'));
    }

    public function test_owner_can_create_product_with_barcode_and_opening_stock(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Opening Stock Market');
        $category = $organization->categories()->create(['name' => 'مشروبات']);
        $secondBranch = $organization->branches()->create([
            'name' => 'الفرع الثاني',
            'code' => 'BR-02',
            'is_main' => false,
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->from(route('products.index'))
            ->post(route('products.store'), [
                'name' => 'مياه معدنية',
                'sku' => 'water-001',
                'category_id' => $category->getKey(),
                'base_unit_id' => $this->unitId('piece'),
                'barcode' => '6221234567890',
                'purchase_price' => '5.50',
                'selling_price' => '7.00',
                'low_stock_threshold' => '3.000',
                'initial_stock' => '20.000',
            ])
            ->assertRedirect(route('products.index'));

        $product = Product::query()->where('organization_id', $organization->getKey())->where('sku', 'WATER-001')->firstOrFail();

        $this->assertDatabaseHas('product_barcodes', [
            'organization_id' => $organization->getKey(),
            'product_id' => $product->getKey(),
            'barcode' => '6221234567890',
        ]);
        $this->assertDatabaseHas('product_unit_conversions', [
            'product_id' => $product->getKey(),
            'unit_id' => $this->unitId('piece'),
            'factor_to_base' => '1.000',
        ]);

        $balance = InventoryBalance::query()
            ->where('organization_id', $organization->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('product_id', $product->getKey())
            ->firstOrFail();

        $this->assertSame('20.000', (string) $balance->quantity);
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(),
            'branch_id' => $secondBranch->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => '0.000',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->getKey(),
            'movement_type' => 'opening',
            'quantity_delta' => '20.000',
            'balance_after' => '20.000',
        ]);
    }

    public function test_sku_must_be_unique_within_an_organization(): void
    {
        [$owner, $organization] = $this->createOrganizationFor('Unique SKU Market');
        Product::query()->create([
            'organization_id' => $organization->getKey(),
            'base_unit_id' => $this->unitId('piece'),
            'sku' => 'DUP-001',
            'name' => 'منتج موجود',
            'purchase_price' => '1.00',
            'selling_price' => '2.00',
            'low_stock_threshold' => '0.000',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->from(route('products.index'))
            ->post(route('products.store'), [
                'name' => 'منتج مكرر',
                'sku' => 'dup-001',
                'base_unit_id' => $this->unitId('piece'),
                'purchase_price' => '1.00',
                'selling_price' => '2.00',
                'low_stock_threshold' => '0',
                'initial_stock' => '0',
            ])
            ->assertRedirect(route('products.index'))
            ->assertSessionHasErrors('sku');
    }

    public function test_duplicate_barcode_is_rejected_within_an_organization(): void
    {
        [$owner, $organization] = $this->createOrganizationFor('Unique Barcode Market');
        $unitId = $this->unitId('piece');
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(),
            'base_unit_id' => $unitId,
            'sku' => 'FIRST-001',
            'name' => 'منتج أول',
            'purchase_price' => '1.00',
            'selling_price' => '2.00',
            'low_stock_threshold' => '0.000',
            'is_active' => true,
        ]);
        $product->barcodes()->create([
            'organization_id' => $organization->getKey(),
            'unit_id' => $unitId,
            'barcode' => '6220000000001',
        ]);

        $this->actingAs($owner)
            ->from(route('products.index'))
            ->post(route('products.store'), [
                'name' => 'منتج ثانٍ',
                'sku' => 'SECOND-001',
                'base_unit_id' => $unitId,
                'barcode' => '6220000000001',
                'purchase_price' => '1.00',
                'selling_price' => '2.00',
                'low_stock_threshold' => '0',
                'initial_stock' => '0',
            ])
            ->assertRedirect(route('products.index'))
            ->assertSessionHasErrors('barcode');
    }

    public function test_cashier_can_view_products_but_cannot_create_products_or_adjust_stock(): void
    {
        [, $organization, $branch] = $this->createOrganizationFor('Cashier Permission Market');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($cashier)
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Index')
                ->where('canManageCatalog', false)
                ->where('canManageInventory', false));

        $this->actingAs($cashier)
            ->post(route('products.store'), [
                'name' => 'محاولة غير مصرح بها',
                'sku' => 'NOPE-001',
                'base_unit_id' => $this->unitId('piece'),
                'purchase_price' => '1.00',
                'selling_price' => '2.00',
                'low_stock_threshold' => '0',
                'initial_stock' => '0',
            ])
            ->assertForbidden();
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

    private function unitId(string $code): int
    {
        return (int) \App\Models\Unit::query()->where('code', $code)->value('id');
    }
}
