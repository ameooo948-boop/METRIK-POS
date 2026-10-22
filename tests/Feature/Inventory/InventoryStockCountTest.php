<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryStockCount;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryStockCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_count_reconciles_stock_and_records_variance_movement(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Inventory Count Variance Market');
        $product = $this->createProduct($organization, $owner, $branch, 'COUNT-001', 'سكر', '10.000');

        $this->actingAs($owner)->post(route('inventory.stock-counts.store'), [
            'client_operation_uuid' => (string) Str::uuid(),
            'notes' => 'جرد نهاية الوردية',
            'items' => [[
                'product_id' => $product->getKey(),
                'expected_quantity' => '10.000',
                'counted_quantity' => '8.500',
            ]],
        ])->assertRedirect(route('inventory.stock-counts.index'));

        $this->assertSame('8.500', (string) InventoryBalance::query()
            ->where('organization_id', $organization->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('product_id', $product->getKey())
            ->value('quantity'));

        $this->assertDatabaseHas('inventory_stock_counts', [
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'status' => 'completed',
            'total_lines' => 1,
            'total_variance_lines' => 1,
            'notes' => 'جرد نهاية الوردية',
        ]);
        $this->assertDatabaseHas('inventory_stock_count_items', [
            'product_id' => $product->getKey(),
            'expected_quantity' => '10.000',
            'counted_quantity' => '8.500',
            'variance_quantity' => '-1.500',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(),
            'movement_type' => 'count_adjustment',
            'quantity_delta' => '-1.500',
            'balance_after' => '8.500',
            'source' => 'stock_count',
        ]);
        $this->assertDatabaseCount('inventory_stock_counts', 1);
    }

    public function test_same_count_uuid_is_idempotent_and_does_not_apply_variance_twice(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Inventory Count Replay Market');
        $product = $this->createProduct($organization, $owner, $branch, 'COUNT-002', 'زيت', '5.000');
        $payload = [
            'client_operation_uuid' => (string) Str::uuid(),
            'items' => [[
                'product_id' => $product->getKey(),
                'expected_quantity' => '5.000',
                'counted_quantity' => '4.000',
            ]],
        ];

        $this->actingAs($owner)->post(route('inventory.stock-counts.store'), $payload)
            ->assertRedirect(route('inventory.stock-counts.index'));
        $this->actingAs($owner)->post(route('inventory.stock-counts.store'), $payload)
            ->assertRedirect(route('inventory.stock-counts.index'));

        $this->assertSame('4.000', (string) InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->value('quantity'));
        $this->assertDatabaseCount('inventory_stock_counts', 1);
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', 'count_adjustment')->count());
    }

    public function test_reusing_count_uuid_with_different_payload_is_rejected(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Inventory Count UUID Market');
        $product = $this->createProduct($organization, $owner, $branch, 'COUNT-003', 'أرز', '9.000');
        $payload = [
            'client_operation_uuid' => (string) Str::uuid(),
            'items' => [[
                'product_id' => $product->getKey(),
                'expected_quantity' => '9.000',
                'counted_quantity' => '8.000',
            ]],
        ];

        $this->actingAs($owner)->post(route('inventory.stock-counts.store'), $payload)
            ->assertRedirect(route('inventory.stock-counts.index'));
        $payload['items'][0]['counted_quantity'] = '7.000';
        $this->actingAs($owner)->from(route('inventory.stock-counts.index'))
            ->post(route('inventory.stock-counts.store'), $payload)
            ->assertRedirect(route('inventory.stock-counts.index'))
            ->assertSessionHasErrors('client_operation_uuid');

        $this->assertSame('8.000', (string) InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->value('quantity'));
        $this->assertDatabaseCount('inventory_stock_counts', 1);
    }

    public function test_count_is_rejected_if_book_balance_changed_after_page_snapshot(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Inventory Count Stale Snapshot Market');
        $product = $this->createProduct($organization, $owner, $branch, 'COUNT-004', 'شاي', '10.000');
        InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->update(['quantity' => '9.000']);

        $this->actingAs($owner)->from(route('inventory.stock-counts.index'))
            ->post(route('inventory.stock-counts.store'), [
                'client_operation_uuid' => (string) Str::uuid(),
                'items' => [[
                    'product_id' => $product->getKey(),
                    'expected_quantity' => '10.000',
                    'counted_quantity' => '8.000',
                ]],
            ])
            ->assertRedirect(route('inventory.stock-counts.index'))
            ->assertSessionHasErrors('items');

        $this->assertSame('9.000', (string) InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->value('quantity'));
        $this->assertDatabaseCount('inventory_stock_counts', 0);
    }

    public function test_zero_variance_is_saved_without_creating_adjustment_movement(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Inventory Count No Variance Market');
        $product = $this->createProduct($organization, $owner, $branch, 'COUNT-005', 'مكرونة', '6.125');
        $movementsBefore = InventoryMovement::query()->count();

        $this->actingAs($owner)->post(route('inventory.stock-counts.store'), [
            'client_operation_uuid' => (string) Str::uuid(),
            'items' => [[
                'product_id' => $product->getKey(),
                'expected_quantity' => '6.125',
                'counted_quantity' => '6.125',
            ]],
        ])->assertRedirect(route('inventory.stock-counts.index'));

        $this->assertDatabaseHas('inventory_stock_counts', ['total_lines' => 1, 'total_variance_lines' => 0]);
        $this->assertDatabaseCount('inventory_stock_count_items', 1);
        $this->assertSame($movementsBefore, InventoryMovement::query()->count());
        $this->assertSame('6.125', (string) InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->value('quantity'));
    }

    public function test_cashier_cannot_view_or_create_stock_counts(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Inventory Count Permissions Market');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $branch->users()->attach($cashier->getKey());
        $product = $this->createProduct($organization, $owner, $branch, 'COUNT-006', 'دقيق', '4.000');

        $this->actingAs($cashier)->get(route('inventory.stock-counts.index'))->assertForbidden();
        $this->actingAs($cashier)->post(route('inventory.stock-counts.store'), [
            'client_operation_uuid' => (string) Str::uuid(),
            'items' => [[
                'product_id' => $product->getKey(),
                'expected_quantity' => '4.000',
                'counted_quantity' => '3.000',
            ]],
        ])->assertForbidden();

        $this->assertDatabaseCount('inventory_stock_counts', 0);
        $this->assertSame('4.000', (string) InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->value('quantity'));
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

    private function createProduct(Organization $organization, User $owner, Branch $branch, string $sku, string $name, string $quantity): Product
    {
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(),
            'base_unit_id' => (int) Unit::query()->where('code', 'piece')->value('id'),
            'created_by' => $owner->getKey(),
            'sku' => $sku, 'name' => $name,
            'purchase_price' => '5.00', 'selling_price' => '8.00',
            'low_stock_threshold' => '1.000', 'is_active' => true,
        ]);
        ProductUnitConversion::query()->create([
            'product_id' => $product->getKey(), 'unit_id' => $product->base_unit_id, 'factor_to_base' => '1.000',
        ]);
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => $quantity,
        ]);
        InventoryMovement::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'created_by' => $owner->getKey(),
            'movement_type' => 'opening', 'quantity_delta' => $quantity, 'balance_after' => $quantity,
            'source' => 'test', 'reason' => 'رصيد افتتاحي للاختبار',
        ]);

        return $product;
    }
}
