<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryLotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_register_a_lot_without_changing_branch_stock(): void
    {
        [$owner, $organization, $branch, $product] = $this->makeProduct('Lots Registration', '12.000');

        $this->actingAs($owner)->post(route('inventory.lots.store'), [
            'product_id' => $product->getKey(), 'batch_code' => 'BATCH-001', 'manufactured_at' => now()->subMonths(2)->toDateString(),
            'expires_at' => now()->addMonths(4)->toDateString(), 'received_at' => now()->subMonth()->toDateString(), 'quantity' => '5.000',
        ])->assertRedirect(route('inventory.lots.index'));

        $this->assertDatabaseHas('inventory_lots', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(),
            'batch_code' => 'BATCH-001', 'quantity_received' => '5.000', 'quantity_remaining' => '5.000',
        ]);
        $this->assertDatabaseHas('inventory_balances', ['branch_id' => $branch->getKey(), 'product_id' => $product->getKey(), 'quantity' => '12.000']);
        $this->assertDatabaseHas('inventory_lot_movements', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'movement_type' => 'lot_registration', 'quantity_delta' => '5.000', 'balance_after' => '5.000',
        ]);
    }

    public function test_lot_quantity_cannot_exceed_untracked_branch_stock(): void
    {
        [$owner, $organization, $branch, $product] = $this->makeProduct('Lots Over Allocation', '3.000');

        $this->actingAs($owner)->from(route('inventory.lots.index'))->post(route('inventory.lots.store'), [
            'product_id' => $product->getKey(), 'batch_code' => 'BATCH-TOO-MUCH', 'expires_at' => now()->addMonth()->toDateString(),
            'received_at' => now()->toDateString(), 'quantity' => '4.000',
        ])->assertRedirect(route('inventory.lots.index'))->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('inventory_lots', 0);
        $this->assertDatabaseHas('inventory_balances', ['branch_id' => $branch->getKey(), 'product_id' => $product->getKey(), 'quantity' => '3.000']);
    }

    public function test_expired_lots_can_be_registered_and_are_shown_as_expired(): void
    {
        [$owner, $organization, $branch, $product] = $this->makeProduct('Expired Lots', '8.000');
        InventoryLot::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(),
            'created_by' => $owner->getKey(), 'batch_code' => 'OLD-BATCH', 'expires_at' => now()->subDay()->toDateString(),
            'received_at' => now()->subMonths(2)->toDateString(), 'quantity_received' => '2.000', 'quantity_remaining' => '2.000',
        ]);

        $this->actingAs($owner)->get(route('inventory.lots.index', ['status' => 'expired']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Lots')->where('summary.expiredLots', 1)
                ->has('lots.data', 1)->where('lots.data.0.status', 'expired'));
    }

    public function test_owner_can_view_lot_movement_history(): void
    {
        [$owner, $organization, $branch, $product] = $this->makeProduct('Lots History', '5.000');
        $lot = InventoryLot::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(),
            'created_by' => $owner->getKey(), 'batch_code' => 'HISTORY-BATCH',
            'expires_at' => now('Africa/Cairo')->addDays(30)->toDateString(), 'received_at' => now('Africa/Cairo')->toDateString(),
            'quantity_received' => '3.000', 'quantity_remaining' => '2.000',
        ]);
        InventoryLotMovement::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(),
            'inventory_lot_id' => $lot->getKey(), 'created_by' => $owner->getKey(), 'movement_type' => 'stock_out',
            'quantity_delta' => '-1.000', 'balance_after' => '2.000', 'reason' => 'حركة اختبار',
        ]);

        $this->actingAs($owner)->get(route('inventory.lots.movements', $lot->getKey()))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/LotMovements')->where('lot.batchCode', 'HISTORY-BATCH')
                ->where('movements.data.0.typeLabel', 'تسوية صرف')->where('movements.total', 1));
    }

    public function test_cashier_cannot_manage_expiry_lots(): void
    {
        [$owner, $organization, $branch, $product] = $this->makeProduct('Lots Permissions', '5.000');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($cashier)->get(route('inventory.lots.index'))->assertForbidden();
        $this->actingAs($cashier)->post(route('inventory.lots.store'), [
            'product_id' => $product->getKey(), 'batch_code' => 'NO-PERMISSION', 'expires_at' => now()->addMonth()->toDateString(),
            'received_at' => now()->toDateString(), 'quantity' => '1.000',
        ])->assertForbidden();
        $this->assertDatabaseCount('inventory_lots', 0);
    }

    private function makeProduct(string $name, string $quantity): array
    {
        $owner = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'status' => 'trial',
            'timezone' => 'Africa/Cairo', 'currency' => 'EGP',
        ]);
        $branch = $organization->branches()->create(['name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'status' => 'active']);
        $organization->users()->attach($owner->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($owner->getKey());
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(), 'base_unit_id' => (int) Unit::query()->where('code', 'piece')->value('id'),
            'sku' => 'LOT-'.Str::upper(Str::random(6)), 'name' => $name, 'purchase_price' => '5.00', 'selling_price' => '8.00',
            'low_stock_threshold' => '2.000', 'is_active' => true,
        ]);
        InventoryBalance::query()->create(['organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(), 'quantity' => $quantity]);
        return [$owner, $organization, $branch, $product];
    }
}
