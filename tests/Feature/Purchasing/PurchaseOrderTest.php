<?php

namespace Tests\Feature\Purchasing;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_does_not_change_stock_and_partial_receipts_create_invoices_and_update_stock(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Purchase Order Market');
        $supplier = Supplier::query()->create(['organization_id' => $organization->getKey(), 'name' => 'مورد تجريبي', 'is_active' => true]);
        $product = $this->createProduct($organization, $owner, $branch, 'PO-ITEM-001', 'مياه', '4.000');
        $unit = Unit::query()->whereKey($product->base_unit_id)->firstOrFail();

        $this->actingAs($owner)->post(route('purchase-orders.store'), [
            'supplier_id' => $supplier->getKey(),
            'expected_at' => now()->addDays(3)->toDateString(),
            'notes' => 'طلب تجريبي',
            'items' => [[
                'product_id' => $product->getKey(), 'unit_id' => $unit->getKey(),
                'quantity' => '5.000', 'unit_cost' => '3.50',
            ]],
        ])->assertRedirect(route('purchase-orders.index'));

        $order = PurchaseOrder::query()->where('organization_id', $organization->getKey())->firstOrFail();
        $orderItem = $order->items()->firstOrFail();
        $this->assertSame('ordered', $order->status);
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => '4.000',
        ]);

        $this->actingAs($owner)->post(route('purchase-orders.receive', $order->getKey()), [
            'paid_amount' => '2.00',
            'supplier_invoice_number' => 'SUP-RECEIPT-1',
            'items' => [['order_item_id' => $orderItem->getKey(), 'quantity' => '2.000']],
        ])->assertRedirect(route('purchase-orders.index'));

        $order->refresh();
        $this->assertSame('partially_received', $order->status);
        $this->assertDatabaseHas('purchase_order_items', [
            'id' => $orderItem->getKey(), 'quantity_ordered' => '5.000', 'quantity_received' => '2.000',
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => '6.000',
        ]);
        $this->assertDatabaseHas('purchase_invoices', [
            'organization_id' => $organization->getKey(), 'purchase_order_id' => $order->getKey(),
            'supplier_invoice_number' => 'SUP-RECEIPT-1', 'total' => '7.00',
        ]);

        $this->actingAs($owner)->post(route('purchase-orders.receive', $order->getKey()), [
            'paid_amount' => '0.00',
            'items' => [['order_item_id' => $orderItem->getKey(), 'quantity' => '4.000']],
        ])->assertSessionHasErrors('items');
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => '6.000',
        ]);

        $this->actingAs($owner)->post(route('purchase-orders.receive', $order->getKey()), [
            'paid_amount' => '0.00',
            'items' => [['order_item_id' => $orderItem->getKey(), 'quantity' => '3.000']],
        ])->assertRedirect(route('purchase-orders.index'));

        $order->refresh();
        $this->assertSame('received', $order->status);
        $this->assertDatabaseHas('purchase_order_items', ['id' => $orderItem->getKey(), 'quantity_received' => '5.000']);
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => '9.000',
        ]);
        $this->assertDatabaseCount('purchase_invoices', 2);
        $this->assertDatabaseHas('purchase_invoices', ['organization_id' => $organization->getKey(), 'purchase_order_id' => $order->getKey(), 'total' => '10.50']);
    }

    private function createOrganizationFor(string $name): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'trial', 'timezone' => 'Africa/Cairo', 'currency' => 'EGP',
        ]);
        $branch = $organization->branches()->create(['name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'status' => 'active']);
        $organization->users()->attach($user->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($user->getKey());
        return [$user, $organization, $branch];
    }

    private function createProduct(Organization $organization, User $owner, $branch, string $sku, string $name, string $quantity): Product
    {
        $piece = Unit::query()->where('code', 'piece')->firstOrFail();
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(), 'base_unit_id' => $piece->getKey(),
            'created_by' => $owner->getKey(), 'sku' => $sku, 'name' => $name,
            'purchase_price' => '1.00', 'selling_price' => '2.00', 'low_stock_threshold' => '1.000', 'is_active' => true,
        ]);
        ProductUnitConversion::query()->create(['product_id' => $product->getKey(), 'unit_id' => $piece->getKey(), 'factor_to_base' => '1.000']);
        InventoryBalance::query()->create(['organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(), 'quantity' => $quantity]);
        InventoryMovement::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(),
            'created_by' => $owner->getKey(), 'movement_type' => 'opening', 'quantity_delta' => $quantity,
            'balance_after' => $quantity, 'source' => 'test', 'reason' => 'رصيد افتتاحي للاختبار',
        ]);
        return $product;
    }
}
