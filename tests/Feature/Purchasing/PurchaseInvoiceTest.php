<?php

namespace Tests\Feature\Purchasing;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierTransaction;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_receiving_purchase_invoice_updates_stock_cost_and_supplier_ledger_atomically(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Purchase Receipt Market');
        $supplier = Supplier::query()->create(['organization_id' => $organization->getKey(), 'name' => 'مورد تجريبي', 'is_active' => true]);
        $product = $this->createProduct($organization, $owner, $branch, 'WATER-001', 'مياه معدنية', '10.000');

        $this->actingAs($owner)->post(route('purchase-invoices.store'), [
            'supplier_id' => $supplier->getKey(),
            'supplier_invoice_number' => 'SUP-INV-1',
            'paid_amount' => '5.00',
            'notes' => 'استلام تجريبي',
            'items' => [[
                'product_id' => $product->getKey(), 'quantity' => '3.500', 'unit_cost' => '6.25',
            ]],
        ])->assertRedirect(route('purchase-invoices.index'));

        $this->assertDatabaseHas('purchase_invoices', [
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'supplier_id' => $supplier->getKey(),
            'total' => '21.88', 'paid_amount' => '5.00', 'due_amount' => '16.88', 'status' => 'received',
        ]);
        $this->assertDatabaseHas('purchase_invoice_items', [
            'product_id' => $product->getKey(), 'quantity' => '3.500', 'unit_cost' => '6.25', 'line_total' => '21.88',
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => '13.500',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'movement_type' => 'purchase', 'quantity_delta' => '3.500',
            'source' => 'purchase_invoice',
        ]);
        $this->assertDatabaseHas('products', ['id' => $product->getKey(), 'purchase_price' => '6.25']);
        $this->assertDatabaseHas('supplier_transactions', [
            'supplier_id' => $supplier->getKey(), 'transaction_type' => 'purchase_invoice', 'credit' => '21.88', 'debit' => '0.00',
        ]);
        $this->assertDatabaseHas('supplier_transactions', [
            'supplier_id' => $supplier->getKey(), 'transaction_type' => 'payment', 'debit' => '5.00', 'credit' => '0.00', 'balance_after' => '16.88',
        ]);

        $this->actingAs($owner)->get(route('purchase-invoices.index'))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Purchasing/Index')
                ->has('invoices.data', 1)
                ->where('invoices.data.0.dueAmount', '16.88'));
    }

    public function test_invoice_can_only_use_supplier_and_product_from_the_current_organization(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Local Purchase Market');
        [$otherOwner, $otherOrganization, $otherBranch] = $this->createOrganizationFor('Other Purchase Market');
        $otherSupplier = Supplier::query()->create(['organization_id' => $otherOrganization->getKey(), 'name' => 'مورد مؤسسة أخرى']);
        $otherProduct = $this->createProduct($otherOrganization, $otherOwner, $otherBranch, 'OTHER-001', 'منتج آخر', '4.000');

        $this->actingAs($owner)->from(route('purchase-invoices.index'))->post(route('purchase-invoices.store'), [
            'supplier_id' => $otherSupplier->getKey(), 'paid_amount' => '0.00',
            'items' => [['product_id' => $otherProduct->getKey(), 'quantity' => '1.000', 'unit_cost' => '1.00']],
        ])->assertRedirect(route('purchase-invoices.index'))
            ->assertSessionHasErrors(['supplier_id', 'items.0.product_id']);

        $this->assertDatabaseCount('purchase_invoices', 0);
    }

    public function test_initial_payment_cannot_exceed_invoice_total(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Overpayment Test Market');
        $supplier = Supplier::query()->create(['organization_id' => $organization->getKey(), 'name' => 'مورد']);
        $product = $this->createProduct($organization, $owner, $branch, 'MILK-001', 'لبن', '1.000');

        $this->actingAs($owner)->from(route('purchase-invoices.index'))->post(route('purchase-invoices.store'), [
            'supplier_id' => $supplier->getKey(), 'paid_amount' => '20.00',
            'items' => [['product_id' => $product->getKey(), 'quantity' => '2.000', 'unit_cost' => '5.00']],
        ])->assertRedirect(route('purchase-invoices.index'))->assertSessionHasErrors('paid_amount');

        $this->assertDatabaseCount('purchase_invoices', 0);
        $this->assertDatabaseCount('supplier_transactions', 0);
        $balance = InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())->firstOrFail();
        $this->assertSame('1.000', (string) $balance->quantity);
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

    private function createProduct(Organization $organization, User $owner, $branch, string $sku, string $name, string $quantity): Product
    {
        $unitId = (int) Unit::query()->where('code', 'piece')->value('id');
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(), 'base_unit_id' => $unitId, 'created_by' => $owner->getKey(),
            'sku' => $sku, 'name' => $name, 'purchase_price' => '5.00', 'selling_price' => '8.00',
            'low_stock_threshold' => '1.000', 'is_active' => true,
        ]);
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => $quantity,
        ]);

        return $product;
    }
}
