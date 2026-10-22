<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_moves_fractional_stock_between_branches_and_records_both_movements(): void
    {
        [$owner, $organization, $source] = $this->createOrganizationFor('Stock Transfer Main');
        $destination = $this->createBranch($organization, 'فرع الاستلام', 'DST-01');
        $product = $this->createProduct($organization, $owner, $source, 'TRANSFER-001', 'زيت', '10.000');

        $this->actingAs($owner)->post(route('inventory.transfers.store'), [
            'destination_branch_id' => $destination->getKey(),
            'client_operation_uuid' => (string) Str::uuid(),
            'notes' => 'توزيع مخزون',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '3.125']],
        ])->assertRedirect(route('inventory.transfers.index'));

        $this->assertSame('6.875', (string) InventoryBalance::query()->where('branch_id', $source->getKey())->where('product_id', $product->getKey())->value('quantity'));
        $this->assertSame('3.125', (string) InventoryBalance::query()->where('branch_id', $destination->getKey())->where('product_id', $product->getKey())->value('quantity'));
        $this->assertDatabaseHas('stock_transfers', [
            'organization_id' => $organization->getKey(),
            'source_branch_id' => $source->getKey(),
            'destination_branch_id' => $destination->getKey(),
            'status' => 'completed',
            'total_lines' => 1,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'branch_id' => $source->getKey(), 'product_id' => $product->getKey(),
            'movement_type' => 'transfer_out', 'quantity_delta' => '-3.125', 'balance_after' => '6.875',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'branch_id' => $destination->getKey(), 'product_id' => $product->getKey(),
            'movement_type' => 'transfer_in', 'quantity_delta' => '3.125', 'balance_after' => '3.125',
        ]);
        $this->assertDatabaseCount('stock_transfers', 1);
    }

    public function test_replaying_the_same_transfer_uuid_does_not_move_stock_twice(): void
    {
        [$owner, $organization, $source] = $this->createOrganizationFor('Stock Transfer Retry');
        $destination = $this->createBranch($organization, 'فرع مستلم', 'DST-02');
        $product = $this->createProduct($organization, $owner, $source, 'TRANSFER-002', 'سكر', '8.000');
        $payload = [
            'destination_branch_id' => $destination->getKey(),
            'client_operation_uuid' => (string) Str::uuid(),
            'notes' => 'نفس الطلب',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '2.000']],
        ];

        $this->actingAs($owner)->post(route('inventory.transfers.store'), $payload)->assertRedirect(route('inventory.transfers.index'));
        $this->actingAs($owner)->post(route('inventory.transfers.store'), $payload)->assertRedirect(route('inventory.transfers.index'));

        $this->assertSame('6.000', (string) InventoryBalance::query()->where('branch_id', $source->getKey())->where('product_id', $product->getKey())->value('quantity'));
        $this->assertSame('2.000', (string) InventoryBalance::query()->where('branch_id', $destination->getKey())->where('product_id', $product->getKey())->value('quantity'));
        $this->assertDatabaseCount('stock_transfers', 1);
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', 'transfer_out')->count());
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', 'transfer_in')->count());
    }

    public function test_same_uuid_with_a_different_payload_is_rejected(): void
    {
        [$owner, $organization, $source] = $this->createOrganizationFor('Stock Transfer UUID');
        $destination = $this->createBranch($organization, 'فرع مستلم', 'DST-03');
        $product = $this->createProduct($organization, $owner, $source, 'TRANSFER-003', 'أرز', '8.000');
        $uuid = (string) Str::uuid();
        $payload = [
            'destination_branch_id' => $destination->getKey(), 'client_operation_uuid' => $uuid,
            'notes' => null, 'items' => [['product_id' => $product->getKey(), 'quantity' => '2.000']],
        ];

        $this->actingAs($owner)->post(route('inventory.transfers.store'), $payload)->assertRedirect(route('inventory.transfers.index'));
        $payload['items'][0]['quantity'] = '3.000';
        $this->actingAs($owner)->from(route('inventory.transfers.index'))
            ->post(route('inventory.transfers.store'), $payload)
            ->assertRedirect(route('inventory.transfers.index'))
            ->assertSessionHasErrors('client_operation_uuid');

        $this->assertSame('6.000', (string) InventoryBalance::query()->where('branch_id', $source->getKey())->where('product_id', $product->getKey())->value('quantity'));
        $this->assertSame('2.000', (string) InventoryBalance::query()->where('branch_id', $destination->getKey())->where('product_id', $product->getKey())->value('quantity'));
        $this->assertDatabaseCount('stock_transfers', 1);
    }

    public function test_transfer_rejects_insufficient_source_stock_without_partial_changes(): void
    {
        [$owner, $organization, $source] = $this->createOrganizationFor('Stock Transfer Oversell');
        $destination = $this->createBranch($organization, 'فرع مستلم', 'DST-04');
        $product = $this->createProduct($organization, $owner, $source, 'TRANSFER-004', 'لبن', '1.000');

        $this->actingAs($owner)->from(route('inventory.transfers.index'))->post(route('inventory.transfers.store'), [
            'destination_branch_id' => $destination->getKey(),
            'client_operation_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->getKey(), 'quantity' => '1.001']],
        ])->assertRedirect(route('inventory.transfers.index'))->assertSessionHasErrors('items');

        $this->assertSame('1.000', (string) InventoryBalance::query()->where('branch_id', $source->getKey())->where('product_id', $product->getKey())->value('quantity'));
        $this->assertSame(0, InventoryBalance::query()->where('branch_id', $destination->getKey())->where('product_id', $product->getKey())->count());
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseCount('inventory_movements', 1); // only the opening movement
    }

    public function test_transfer_cannot_target_a_branch_from_another_organization(): void
    {
        [$owner, $organization, $source] = $this->createOrganizationFor('Stock Transfer Tenant A');
        [$otherOwner, $otherOrganization, $foreignBranch] = $this->createOrganizationFor('Stock Transfer Tenant B');
        $product = $this->createProduct($organization, $owner, $source, 'TRANSFER-005', 'دقيق', '5.000');

        $this->actingAs($owner)->from(route('inventory.transfers.index'))->post(route('inventory.transfers.store'), [
            'destination_branch_id' => $foreignBranch->getKey(),
            'client_operation_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->getKey(), 'quantity' => '1.000']],
        ])->assertRedirect(route('inventory.transfers.index'))->assertSessionHasErrors('destination_branch_id');

        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertSame('5.000', (string) InventoryBalance::query()->where('branch_id', $source->getKey())->where('product_id', $product->getKey())->value('quantity'));
    }

    public function test_cashier_cannot_view_or_create_stock_transfers(): void
    {
        [$owner, $organization, $source] = $this->createOrganizationFor('Stock Transfer Permissions');
        $destination = $this->createBranch($organization, 'فرع آخر', 'DST-06');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $source->users()->attach($cashier->getKey());
        $product = $this->createProduct($organization, $owner, $source, 'TRANSFER-006', 'شاي', '4.000');

        $this->actingAs($cashier)->get(route('inventory.transfers.index'))->assertForbidden();
        $this->actingAs($cashier)->post(route('inventory.transfers.store'), [
            'destination_branch_id' => $destination->getKey(),
            'client_operation_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->getKey(), 'quantity' => '1.000']],
        ])->assertForbidden();

        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertSame('4.000', (string) InventoryBalance::query()->where('branch_id', $source->getKey())->where('product_id', $product->getKey())->value('quantity'));
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

    private function createBranch(Organization $organization, string $name, string $code): Branch
    {
        return $organization->branches()->create([
            'name' => $name, 'code' => $code, 'is_main' => false, 'status' => 'active',
        ]);
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
