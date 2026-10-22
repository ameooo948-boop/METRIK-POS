<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\CashShift;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryLot;
use App\Models\InventoryLotSaleAllocation;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PointOfSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_shift_and_view_pos_page(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('POS Main Market');

        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '200.00'])
            ->assertRedirect(route('pos.index'));

        $this->actingAs($owner)->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('POS/Index')
                ->where('organization.name', 'POS Main Market')
                ->where('shift.openingAmount', '200.00'));

        $this->assertDatabaseHas('cash_shifts', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'cashier_id' => $owner->getKey(), 'status' => 'open', 'opening_amount' => '200.00',
        ]);
    }

    public function test_opening_a_second_shift_for_same_cashier_and_branch_is_rejected(): void
    {
        [$owner] = $this->createOrganizationFor('POS One Shift Market');
        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '100.00'])->assertRedirect(route('pos.index'));
        $this->actingAs($owner)->from(route('pos.index'))->post(route('pos.shifts.open'), ['opening_amount' => '50.00'])
            ->assertRedirect(route('pos.index'))->assertSessionHasErrors('opening_amount');

        $this->assertDatabaseCount('cash_shifts', 1);
    }

    public function test_checkout_records_sale_payment_movement_and_deducts_stock(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('POS Checkout Market');
        $product = $this->createProduct($organization, $owner, $branch, 'WATER-001', 'مياه معدنية', '10.000');
        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '100.00'])->assertRedirect(route('pos.index'));
        $uuid = (string) Str::uuid();

        $this->actingAs($owner)->post(route('pos.checkout'), [
            'client_operation_uuid' => $uuid,
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '2.000']],
        ])->assertRedirect();

        $sale = Sale::query()->where('organization_id', $organization->getKey())->where('client_operation_uuid', $uuid)->firstOrFail();
        $this->assertSame('16.00', $sale->total);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->getKey(), 'product_id' => $product->getKey(), 'quantity' => '2.000',
            'unit_price' => '8.00', 'line_total' => '16.00',
        ]);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale->getKey(), 'payment_method' => 'cash', 'amount' => '16.00']);
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => '8.000',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->getKey(), 'movement_type' => 'sale', 'quantity_delta' => '-2.000',
            'reference_type' => Sale::class, 'reference_id' => $sale->getKey(),
        ]);
        $this->assertDatabaseHas('cash_movements', [
            'cash_shift_id' => $sale->cash_shift_id, 'sale_id' => $sale->getKey(),
            'movement_type' => 'cash_sale', 'amount' => '16.00',
        ]);
    }

    public function test_checkout_consumes_expiring_lots_first_and_persists_lot_allocations(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('POS Lot FEFO Market');
        $product = $this->createProduct($organization, $owner, $branch, 'LOT-FEFO-001', 'منتج له تشغيلات', '10.000');
        $firstLot = InventoryLot::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(),
            'created_by' => $owner->getKey(), 'batch_code' => 'FEFO-NEAR', 'expires_at' => now('Africa/Cairo')->addDays(5)->toDateString(),
            'received_at' => now('Africa/Cairo')->subDays(5)->toDateString(), 'quantity_received' => '2.000', 'quantity_remaining' => '2.000',
        ]);
        $secondLot = InventoryLot::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(),
            'created_by' => $owner->getKey(), 'batch_code' => 'FEFO-FAR', 'expires_at' => now('Africa/Cairo')->addDays(30)->toDateString(),
            'received_at' => now('Africa/Cairo')->subDays(2)->toDateString(), 'quantity_received' => '3.000', 'quantity_remaining' => '3.000',
        ]);
        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '100.00'])->assertRedirect(route('pos.index'));
        $uuid = (string) Str::uuid();

        $this->actingAs($owner)->post(route('pos.checkout'), [
            'client_operation_uuid' => $uuid, 'payment_method' => 'cash',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '4.000']],
        ])->assertRedirect();

        $sale = Sale::query()->where('organization_id', $organization->getKey())->where('client_operation_uuid', $uuid)->firstOrFail();
        $saleItem = $sale->items()->firstOrFail();
        $this->assertDatabaseHas('inventory_lot_sale_allocations', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'sale_item_id' => $saleItem->getKey(), 'inventory_lot_id' => $firstLot->getKey(),
            'quantity_sold' => '2.000', 'quantity_returned' => '0.000',
        ]);
        $this->assertDatabaseHas('inventory_lot_sale_allocations', [
            'sale_item_id' => $saleItem->getKey(), 'inventory_lot_id' => $secondLot->getKey(),
            'quantity_sold' => '2.000', 'quantity_returned' => '0.000',
        ]);
        $this->assertSame('0.000', (string) $firstLot->fresh()->quantity_remaining);
        $this->assertSame('1.000', (string) $secondLot->fresh()->quantity_remaining);
        $this->assertDatabaseCount('inventory_lot_movements', 2);
        $this->assertSame('6.000', (string) InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->value('quantity'));
    }

    public function test_checkout_applies_organization_tax_after_discounts_and_snapshots_the_rate(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('POS Tax Market');
        $organization->forceFill(['settings' => [
            'tax' => ['enabled' => true, 'rate_percent' => '14.00', 'mode' => 'exclusive'],
        ]])->save();
        $product = $this->createProduct($organization, $owner, $branch, 'TAX-001', 'منتج خاضع للضريبة', '10.000');
        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '100.00'])->assertRedirect(route('pos.index'));
        $uuid = (string) Str::uuid();

        $this->actingAs($owner)->post(route('pos.checkout'), [
            'client_operation_uuid' => $uuid,
            'payment_method' => 'cash',
            'manual_discount_percent' => '10.00',
            'expected_total' => '16.42',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '2.000']],
        ])->assertRedirect();

        $sale = Sale::query()->where('organization_id', $organization->getKey())->where('client_operation_uuid', $uuid)->firstOrFail();
        $this->assertSame('16.00', $sale->subtotal);
        $this->assertSame('1.60', $sale->discount_total);
        $this->assertSame('2.02', $sale->tax_total); // 14% of the discounted 14.40 line, rounded half-up.
        $this->assertSame('14.00', $sale->tax_rate_percent);
        $this->assertSame('16.42', $sale->total);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->getKey(), 'product_id' => $product->getKey(),
            'line_total' => '14.40', 'tax_amount' => '2.02',
        ]);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale->getKey(), 'amount' => '16.42']);
        $this->assertDatabaseHas('cash_movements', ['sale_id' => $sale->getKey(), 'movement_type' => 'cash_sale', 'amount' => '16.42']);

        // Changing the organization rate later must not mutate the invoice snapshot.
        $organization->forceFill(['settings' => ['tax' => ['enabled' => true, 'rate_percent' => '20.00', 'mode' => 'exclusive']]])->save();
        $this->assertSame('14.00', $sale->fresh()->tax_rate_percent);
        $this->assertSame('16.42', $sale->fresh()->total);
    }

    public function test_replaying_same_operation_uuid_does_not_double_sell_stock(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('POS Idempotency Market');
        $product = $this->createProduct($organization, $owner, $branch, 'MILK-001', 'لبن', '5.000');
        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '0.00'])->assertRedirect(route('pos.index'));
        $uuid = (string) Str::uuid();
        $payload = [
            'client_operation_uuid' => $uuid,
            'payment_method' => 'card',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '2.000']],
        ];

        $this->actingAs($owner)->post(route('pos.checkout'), $payload)->assertRedirect();
        $this->actingAs($owner)->post(route('pos.checkout'), $payload)->assertRedirect();

        $balance = InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->firstOrFail();
        $this->assertSame('3.000', (string) $balance->quantity);
        $this->assertSame(1, Sale::query()->where('organization_id', $organization->getKey())->count());
        $this->assertSame(1, SalePayment::query()->count());
        $this->assertSame(2, InventoryMovement::query()->where('product_id', $product->getKey())->count()); // opening + one sale
    }

    public function test_reusing_operation_uuid_with_a_different_cart_is_rejected(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('POS UUID Payload Market');
        $product = $this->createProduct($organization, $owner, $branch, 'JUICE-UUID', 'عصير تجريبي', '10.000');
        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '0.00'])->assertRedirect(route('pos.index'));
        $uuid = (string) Str::uuid();

        $this->actingAs($owner)->post(route('pos.checkout'), [
            'client_operation_uuid' => $uuid, 'payment_method' => 'cash',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '1.000']],
        ])->assertRedirect();

        $this->actingAs($owner)->from(route('pos.index'))->post(route('pos.checkout'), [
            'client_operation_uuid' => $uuid, 'payment_method' => 'cash',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '2.000']],
        ])->assertRedirect(route('pos.index'))->assertSessionHasErrors('client_operation_uuid');

        $balance = InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->firstOrFail();
        $this->assertSame('9.000', (string) $balance->quantity);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_checkout_rejects_insufficient_stock_without_partial_records(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('POS Oversell Market');
        $product = $this->createProduct($organization, $owner, $branch, 'JUICE-001', 'عصير', '1.000');
        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '0.00'])->assertRedirect(route('pos.index'));

        $this->actingAs($owner)->from(route('pos.index'))->post(route('pos.checkout'), [
            'client_operation_uuid' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '2.000']],
        ])->assertRedirect(route('pos.index'))->assertSessionHasErrors('items.0.quantity');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $balance = InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->firstOrFail();
        $this->assertSame('1.000', (string) $balance->quantity);
    }

    public function test_checkout_requires_open_shift_and_rejects_foreign_products(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('POS Tenant Market');
        [$otherOwner, $otherOrganization, $otherBranch] = $this->createOrganizationFor('POS Foreign Market');
        $foreignProduct = $this->createProduct($otherOrganization, $otherOwner, $otherBranch, 'FOREIGN-01', 'منتج خارجي', '5.000');

        $this->actingAs($owner)->from(route('pos.index'))->post(route('pos.checkout'), [
            'client_operation_uuid' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'items' => [['product_id' => $foreignProduct->getKey(), 'quantity' => '1.000']],
        ])->assertRedirect(route('pos.index'))->assertSessionHasErrors(['items.0.product_id']);
        $this->assertDatabaseCount('sales', 0);

        $localProduct = $this->createProduct($organization, $owner, $branch, 'LOCAL-01', 'منتج محلي', '5.000');
        $this->actingAs($owner)->from(route('pos.index'))->post(route('pos.checkout'), [
            'client_operation_uuid' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'items' => [['product_id' => $localProduct->getKey(), 'quantity' => '1.000']],
        ])->assertRedirect(route('pos.index'))->assertSessionHasErrors('items');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_checkout_rounds_fractional_quantity_to_two_money_decimals(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('POS Fractional Quantity Market');
        $product = $this->createProduct($organization, $owner, $branch, 'CHEESE-001', 'جبنة بالوزن', '5.000');
        $product->selling_price = '6.25';
        $product->save();
        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '0.00'])->assertRedirect(route('pos.index'));

        $this->actingAs($owner)->post(route('pos.checkout'), [
            'client_operation_uuid' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '3.500']],
        ])->assertRedirect();

        $sale = Sale::query()->where('organization_id', $organization->getKey())->firstOrFail();
        $this->assertSame('21.88', $sale->total);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->getKey(), 'quantity' => '3.500', 'unit_price' => '6.25', 'line_total' => '21.88',
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => '1.500',
        ]);
    }

    public function test_closing_shift_calculates_expected_cash_and_difference(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('POS Shift Closing Market');
        $product = $this->createProduct($organization, $owner, $branch, 'TEA-001', 'شاي', '10.000');
        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '50.00'])->assertRedirect(route('pos.index'));
        $shift = CashShift::query()->where('cashier_id', $owner->getKey())->where('status', 'open')->firstOrFail();

        $this->actingAs($owner)->post(route('pos.checkout'), [
            'client_operation_uuid' => (string) Str::uuid(), 'payment_method' => 'cash',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '1.000']],
        ])->assertRedirect();

        $this->actingAs($owner)->post(route('pos.shifts.close', $shift->getKey()), ['closing_amount' => '58.00'])
            ->assertRedirect(route('pos.index'));

        $this->assertDatabaseHas('cash_shifts', [
            'id' => $shift->getKey(), 'status' => 'closed', 'expected_cash' => '58.00',
            'closing_amount' => '58.00', 'difference_amount' => '0.00',
        ]);
    }

    private function createOrganizationFor(string $name): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
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
        $unit = Unit::query()->where('code', 'piece')->firstOrFail();
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(), 'base_unit_id' => $unit->getKey(), 'created_by' => $owner->getKey(),
            'sku' => $sku, 'name' => $name, 'purchase_price' => '5.00', 'selling_price' => '8.00',
            'low_stock_threshold' => '1.000', 'is_active' => true,
        ]);
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(), 'quantity' => $quantity,
        ]);
        InventoryMovement::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(),
            'created_by' => $owner->getKey(), 'movement_type' => 'opening', 'quantity_delta' => $quantity, 'balance_after' => $quantity,
            'source' => 'test', 'reason' => 'رصيد افتتاحي للاختبار',
        ]);
        return $product;
    }
}
