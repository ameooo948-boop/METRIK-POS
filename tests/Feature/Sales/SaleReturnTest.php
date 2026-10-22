<?php

namespace Tests\Feature\Sales;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SaleReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_partially_return_sale_restock_inventory_and_reconcile_cash(): void
    {
        [$owner, $organization, $branch, $product, $sale, $shift] = $this->createSale('Return Main Market');
        $saleItem = $sale->items()->firstOrFail();

        $this->actingAs($owner)->post(route('sales.returns.store', $sale->getKey()), $this->payload($saleItem, '1.000'))
            ->assertRedirect(route('sales.show', $sale->getKey()));

        $return = SaleReturn::query()->where('sale_id', $sale->getKey())->firstOrFail();
        $this->assertSame('8.00', $return->total);
        $this->assertSame('partially_returned', $sale->fresh()->return_status);
        $this->assertDatabaseHas('sale_return_items', [
            'sale_return_id' => $return->getKey(), 'sale_item_id' => $saleItem->getKey(),
            'quantity' => '1.000', 'line_total' => '8.00', 'restock' => 1,
        ]);

        $balance = InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->firstOrFail();
        $this->assertSame('9.000', (string) $balance->quantity); // 10 opening - 2 sold + 1 returned
        $this->assertDatabaseHas('inventory_movements', [
            'reference_type' => SaleReturn::class, 'reference_id' => $return->getKey(),
            'movement_type' => 'sale_return', 'quantity_delta' => '1.000', 'balance_after' => '9.000',
        ]);
        $this->assertDatabaseHas('cash_movements', [
            'sale_return_id' => $return->getKey(), 'cash_shift_id' => $shift->getKey(),
            'movement_type' => 'cash_return', 'amount' => '8.00',
        ]);
        $this->assertSame('58.00', $shift->fresh()->expectedCashAmount()); // 50 opening + 16 sale - 8 return
    }

    public function test_return_refunds_original_line_tax_and_reports_profit_excluding_tax(): void
    {
        [$owner, $organization, $branch, $product, $sale, $shift] = $this->createSale('Return Tax Market', '14.00');
        $saleItem = $sale->items()->firstOrFail();

        $this->assertSame('18.24', $sale->total);
        $this->assertSame('2.24', $sale->tax_total);
        $this->actingAs($owner)->post(route('sales.returns.store', $sale->getKey()), $this->payload($saleItem, '1.000'))
            ->assertRedirect(route('sales.show', $sale->getKey()));

        $return = SaleReturn::query()->where('sale_id', $sale->getKey())->firstOrFail();
        $this->assertSame('9.12', $return->total);
        $this->assertSame('1.12', $return->tax_total);
        $this->assertDatabaseHas('sale_return_items', [
            'sale_return_id' => $return->getKey(), 'sale_item_id' => $saleItem->getKey(),
            'line_total' => '8.00', 'tax_amount' => '1.12',
        ]);

        $today = now('Africa/Cairo')->toDateString();
        $this->actingAs($owner)->get(route('reports.index', [
            'from_date' => $today, 'to_date' => $today, 'branch_id' => (string) $branch->getKey(),
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->where('summary.sales', '18.24')
            ->where('summary.returns', '9.12')
            ->where('summary.taxCollected', '1.12')
            ->where('summary.grossProfit', '3.00')
            ->where('daily.0.taxCollected', '1.12'));
    }

    public function test_same_return_uuid_is_idempotent_and_does_not_restock_or_refund_twice(): void
    {
        [$owner, $organization, $branch, $product, $sale, $shift] = $this->createSale('Return Idempotency Market');
        $saleItem = $sale->items()->firstOrFail();
        $payload = $this->payload($saleItem, '1.000');

        $this->actingAs($owner)->post(route('sales.returns.store', $sale->getKey()), $payload)->assertRedirect();
        $this->actingAs($owner)->post(route('sales.returns.store', $sale->getKey()), $payload)->assertRedirect();

        $balance = InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->firstOrFail();
        $this->assertSame('9.000', (string) $balance->quantity);
        $this->assertSame(1, SaleReturn::query()->where('sale_id', $sale->getKey())->count());
        $this->assertSame(1, CashMovement::query()->where('movement_type', 'cash_return')->count());
        $this->assertSame(1, SaleReturnItem::query()->count());
    }

    public function test_return_cannot_exceed_unreturned_sold_quantity(): void
    {
        [$owner, $organization, $branch, $product, $sale] = $this->createSale('Return Quantity Market');
        $saleItem = $sale->items()->firstOrFail();

        $this->actingAs($owner)->post(route('sales.returns.store', $sale->getKey()), $this->payload($saleItem, '1.000'))->assertRedirect();
        $payload = $this->payload($saleItem, '2.000');
        $payload['client_operation_uuid'] = (string) Str::uuid();

        $this->actingAs($owner)->from(route('sales.show', $sale->getKey()))
            ->post(route('sales.returns.store', $sale->getKey()), $payload)
            ->assertRedirect(route('sales.show', $sale->getKey()))
            ->assertSessionHasErrors('items');

        $balance = InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->firstOrFail();
        $this->assertSame('9.000', (string) $balance->quantity);
        $this->assertSame(1, SaleReturn::query()->where('sale_id', $sale->getKey())->count());
    }

    public function test_report_uses_organization_local_day_after_midnight_before_utc_rollover(): void
    {
        $previousTestNow = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::parse('2026-10-23 00:30:00', 'Africa/Cairo'));

        try {
            [$owner, $organization, $branch] = $this->createSale('Report Cairo Midnight Market');
            $today = now($organization->timezone)->toDateString();

            $this->actingAs($owner)->get(route('reports.index', [
                'from_date' => $today,
                'to_date' => $today,
                'branch_id' => (string) $branch->getKey(),
            ]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('summary.sales', '16.00')
                ->where('summary.costOfGoods', '10.00')
                ->where('summary.grossProfit', '6.00')
                ->where('daily.0.sales', '16.00'));
        } finally {
            Carbon::setTestNow($previousTestNow);
        }
    }

    public function test_reports_subtract_returned_revenue_and_reverse_cost_only_for_restocked_items(): void
    {
        [$owner, $organization, $branch, $product, $sale] = $this->createSale('Return Reporting Market');
        $saleItem = $sale->items()->firstOrFail();
        $this->actingAs($owner)->post(route('sales.returns.store', $sale->getKey()), $this->payload($saleItem, '1.000'))->assertRedirect();

        $today = now('Africa/Cairo')->toDateString();
        $this->actingAs($owner)->get(route('reports.index', [
            'from_date' => $today,
            'to_date' => $today,
            'branch_id' => (string) $branch->getKey(),
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->where('summary.sales', '16.00')
            ->where('summary.returns', '8.00')
            ->where('summary.netSales', '8.00')
            ->where('summary.costOfGoods', '5.00')
            ->where('summary.grossProfit', '3.00')
            ->where('summary.netProfit', '3.00')
            ->where('daily.0.returns', '8.00')
            ->where('daily.0.netSales', '8.00'));
    }

    public function test_non_restockable_return_does_not_increase_stock_or_reverse_cost(): void
    {
        [$owner, $organization, $branch, $product, $sale] = $this->createSale('Return Damaged Item Market');
        $saleItem = $sale->items()->firstOrFail();
        $payload = $this->payload($saleItem, '1.000');
        $payload['items'][0]['restock'] = false;

        $this->actingAs($owner)->post(route('sales.returns.store', $sale->getKey()), $payload)->assertRedirect();

        $balance = InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->firstOrFail();
        $this->assertSame('8.000', (string) $balance->quantity);
        $this->assertSame(0, InventoryMovement::query()->where('reference_type', SaleReturn::class)->count());
        $this->assertDatabaseHas('sale_return_items', ['sale_return_id' => SaleReturn::query()->firstOrFail()->getKey(), 'restock' => 0]);

        $today = now('Africa/Cairo')->toDateString();
        $this->actingAs($owner)->get(route('reports.index', [
            'from_date' => $today, 'to_date' => $today, 'branch_id' => (string) $branch->getKey(),
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('summary.costOfGoods', '10.00')
            ->where('summary.grossProfit', '-2.00'));
    }

    public function test_non_cash_refund_requires_external_reference(): void
    {
        [$owner, $organization, $branch, $product, $sale] = $this->createSale('Return Reference Market');
        $saleItem = $sale->items()->firstOrFail();
        $payload = $this->payload($saleItem, '1.000');
        $payload['refund_method'] = 'card';
        $payload['refund_reference'] = '';

        $this->actingAs($owner)->from(route('sales.show', $sale->getKey()))
            ->post(route('sales.returns.store', $sale->getKey()), $payload)
            ->assertRedirect(route('sales.show', $sale->getKey()))
            ->assertSessionHasErrors('refund_reference');

        $this->assertDatabaseCount('sale_returns', 0);
    }

    public function test_cashier_cannot_return_a_sale_processed_by_another_cashier(): void
    {
        [$owner, $organization, $branch, $product, $sale] = $this->createSale('Return Cashier Authorization Market');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $branch->users()->attach($cashier->getKey());
        $saleItem = $sale->items()->firstOrFail();

        $this->actingAs($cashier)->post(route('sales.returns.store', $sale->getKey()), $this->payload($saleItem, '1.000'))
            ->assertForbidden();

        $this->assertDatabaseCount('sale_returns', 0);
    }

    public function test_sale_detail_displays_return_availability_and_history(): void
    {
        [$owner, $organization, $branch, $product, $sale] = $this->createSale('Return Detail Market');
        $saleItem = $sale->items()->firstOrFail();
        $payload = $this->payload($saleItem, '1.000');
        $this->actingAs($owner)->post(route('sales.returns.store', $sale->getKey()), $payload)->assertRedirect();

        $this->actingAs($owner)->get(route('sales.show', $sale->getKey()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/Show')
                ->where('sale.returnStatus', 'partially_returned')
                ->where('sale.items.0.availableQuantity', '1.000')
                ->has('sale.returns', 1)
                ->where('canCreateReturn', true));
    }

    /** @return array{0:User,1:Organization,2:Branch,3:Product,4:Sale,5:CashShift} */
    private function createSale(string $name, ?string $taxRatePercent = null): array
    {
        $owner = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'trial', 'timezone' => 'Africa/Cairo', 'currency' => 'EGP',
        ]);
        $branch = $organization->branches()->create([
            'name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'status' => 'active',
        ]);
        if ($taxRatePercent !== null) {
            $organization->forceFill(['settings' => [
                'tax' => ['enabled' => true, 'rate_percent' => $taxRatePercent, 'mode' => 'exclusive'],
            ]])->save();
        }
        $organization->users()->attach($owner->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($owner->getKey());

        $unit = Unit::query()->where('code', 'piece')->firstOrFail();
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(), 'base_unit_id' => $unit->getKey(),
            'created_by' => $owner->getKey(), 'sku' => 'RETURN-001', 'name' => 'منتج مرتجع',
            'purchase_price' => '5.00', 'selling_price' => '8.00', 'low_stock_threshold' => '1.000', 'is_active' => true,
        ]);
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => '10.000',
        ]);
        InventoryMovement::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'created_by' => $owner->getKey(),
            'movement_type' => 'opening', 'quantity_delta' => '10.000', 'balance_after' => '10.000',
            'source' => 'test', 'reason' => 'رصيد افتتاحي للاختبار',
        ]);

        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '50.00'])->assertRedirect(route('pos.index'));
        $this->actingAs($owner)->post(route('pos.checkout'), [
            'client_operation_uuid' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '2.000']],
        ])->assertRedirect();

        $sale = Sale::query()->where('organization_id', $organization->getKey())->firstOrFail();
        $shift = CashShift::query()->where('organization_id', $organization->getKey())->firstOrFail();

        return [$owner, $organization, $branch, $product, $sale, $shift];
    }

    /** @return array<string, mixed> */
    private function payload(SaleItem $saleItem, string $quantity): array
    {
        return [
            'client_operation_uuid' => (string) Str::uuid(),
            'refund_method' => 'cash',
            'refund_reference' => '',
            'reason' => 'المنتج غير مناسب',
            'items' => [[
                'sale_item_id' => $saleItem->getKey(),
                'quantity' => $quantity,
                'restock' => true,
            ]],
        ];
    }
}
