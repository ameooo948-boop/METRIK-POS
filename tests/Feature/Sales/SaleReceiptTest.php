<?php

namespace Tests\Feature\Sales;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SaleReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_receipt_with_item_and_payment_totals(): void
    {
        [$owner, $organization, $sale] = $this->createSale('Receipt Main Market');

        $this->actingAs($owner)->get(route('sales.receipt', $sale->getKey()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/Receipt')
                ->where('organization.id', $organization->getKey())
                ->where('sale.invoiceNumber', $sale->invoice_number)
                ->has('sale.items', 1)
                ->where('sale.items.0.name', 'منتج الإيصال')
                ->where('sale.paidTotal', '16.00')
                ->where('sale.remainingTotal', '0.00')
                ->where('sale.payments.0.method', 'cash'));
    }

    public function test_receipt_is_not_accessible_across_organizations(): void
    {
        [$owner, , $sale] = $this->createSale('Receipt Primary Market');
        [, , $otherSale] = $this->createSale('Receipt Other Market');

        $this->actingAs($owner)->get(route('sales.receipt', $otherSale->getKey()))->assertNotFound();
    }

    /** @return array{0:User,1:Organization,2:Sale} */
    private function createSale(string $name): array
    {
        $owner = User::factory()->create();
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
        $organization->users()->attach($owner->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($owner->getKey());

        $unit = Unit::query()->where('code', 'piece')->firstOrFail();
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(),
            'base_unit_id' => $unit->getKey(),
            'created_by' => $owner->getKey(),
            'sku' => 'RECEIPT-'.strtoupper(Str::random(5)),
            'name' => 'منتج الإيصال',
            'purchase_price' => '5.00',
            'selling_price' => '8.00',
            'low_stock_threshold' => '1.000',
            'is_active' => true,
        ]);
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => '10.000',
        ]);
        InventoryMovement::query()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(),
            'created_by' => $owner->getKey(),
            'movement_type' => 'opening',
            'quantity_delta' => '10.000',
            'balance_after' => '10.000',
            'source' => 'test',
            'reason' => 'رصيد افتتاحي للاختبار',
        ]);

        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '50.00'])
            ->assertRedirect(route('pos.index'));
        $this->actingAs($owner)->post(route('pos.checkout'), [
            'client_operation_uuid' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '2.000']],
        ])->assertRedirect();

        $sale = Sale::query()->where('organization_id', $organization->getKey())->firstOrFail();

        return [$owner, $organization, $sale];
    }
}
