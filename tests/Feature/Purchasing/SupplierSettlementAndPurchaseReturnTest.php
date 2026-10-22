<?php

namespace Tests\Feature\Purchasing;

use App\Models\CashShift;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierTransaction;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierSettlementAndPurchaseReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_return_reduces_stock_invoice_due_and_supplier_balance_once(): void
    {
        [$owner, $organization, $branch, $supplier, $product] = $this->fixture('Return Market', '5.000');
        $invoice = $this->receiveInvoice($owner, $supplier, $product, '2.000', '10.00');
        $item = $invoice->items()->firstOrFail();

        $payload = [
            'client_operation_uuid' => (string) Str::uuid(),
            'reason' => 'بضاعة تالفة',
            'items' => [['purchase_invoice_item_id' => $item->getKey(), 'quantity' => '1.000']],
        ];

        $this->actingAs($owner)->post(route('purchase-invoices.returns.store', $invoice->getKey()), $payload)
            ->assertRedirect(route('purchase-returns.index'));

        $this->assertDatabaseHas('purchase_returns', [
            'organization_id' => $organization->getKey(), 'purchase_invoice_id' => $invoice->getKey(), 'total' => '10.00',
        ]);
        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoice->getKey(), 'returned_amount' => '10.00', 'due_amount' => '10.00',
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(), 'quantity' => '6.000',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'organization_id' => $organization->getKey(), 'product_id' => $product->getKey(), 'movement_type' => 'purchase_return', 'quantity_delta' => '-1.000',
        ]);
        $this->assertDatabaseHas('supplier_transactions', [
            'supplier_id' => $supplier->getKey(), 'transaction_type' => 'purchase_return', 'debit' => '10.00', 'credit' => '0.00',
        ]);

        // Retrying the exact same client operation must not subtract stock twice.
        $this->actingAs($owner)->post(route('purchase-invoices.returns.store', $invoice->getKey()), $payload)
            ->assertRedirect(route('purchase-returns.index'));
        $this->assertDatabaseCount('purchase_returns', 1);
        $this->assertDatabaseHas('inventory_balances', ['branch_id' => $branch->getKey(), 'product_id' => $product->getKey(), 'quantity' => '6.000']);
    }

    public function test_purchase_return_cannot_exceed_remaining_invoice_quantity_or_on_hand_stock(): void
    {
        [$owner, $organization, $branch, $supplier, $product] = $this->fixture('Return Limit Market', '0.500');
        $invoice = $this->receiveInvoice($owner, $supplier, $product, '2.000', '10.00');
        $item = $invoice->items()->firstOrFail();
        // Simulate that most of the received stock has already been sold before the vendor return.
        InventoryBalance::query()->where('organization_id', $organization->getKey())
            ->where('branch_id', $branch->getKey())->where('product_id', $product->getKey())
            ->update(['quantity' => '0.500']);

        $this->actingAs($owner)->from(route('purchase-returns.index'))
            ->post(route('purchase-invoices.returns.store', $invoice->getKey()), [
                'client_operation_uuid' => (string) Str::uuid(),
                'reason' => 'مرتجع بكمية أكبر من المخزون',
                'items' => [['purchase_invoice_item_id' => $item->getKey(), 'quantity' => '1.000']],
            ])->assertRedirect(route('purchase-returns.index'))
            ->assertSessionHasErrors('items');

        $this->assertDatabaseCount('purchase_returns', 0);
        $this->assertDatabaseHas('purchase_invoices', ['id' => $invoice->getKey(), 'returned_amount' => '0.00']);
        $this->assertDatabaseHas('inventory_balances', ['branch_id' => $branch->getKey(), 'product_id' => $product->getKey(), 'quantity' => '0.500']);
    }

    public function test_supplier_payment_updates_due_and_is_idempotent(): void
    {
        [$owner, $organization, $branch, $supplier, $product] = $this->fixture('Payment Market', '0.000');
        $invoice = $this->receiveInvoice($owner, $supplier, $product, '2.000', '10.00');
        $payload = [
            'purchase_invoice_id' => $invoice->getKey(),
            'client_operation_uuid' => (string) Str::uuid(),
            'amount' => '5.00',
            'payment_method' => 'bank_transfer',
            'reference_number' => 'TRX-1001',
            'notes' => 'دفعة تجريبية',
        ];

        $this->actingAs($owner)->post(route('supplier-payments.store'), $payload)
            ->assertRedirect(route('supplier-payments.index'));
        $this->assertDatabaseHas('purchase_invoices', ['id' => $invoice->getKey(), 'paid_amount' => '5.00', 'due_amount' => '15.00']);
        $this->assertDatabaseHas('supplier_payments', ['organization_id' => $organization->getKey(), 'purchase_invoice_id' => $invoice->getKey(), 'amount' => '5.00']);
        $this->assertDatabaseHas('supplier_transactions', ['supplier_id' => $supplier->getKey(), 'transaction_type' => 'payment', 'debit' => '5.00']);

        $this->actingAs($owner)->post(route('supplier-payments.store'), $payload)
            ->assertRedirect(route('supplier-payments.index'));
        $this->assertDatabaseCount('supplier_payments', 1);
        $this->assertDatabaseCount('supplier_transactions', 2); // invoice credit plus exactly one payment debit
    }

    public function test_cash_supplier_payment_records_cash_out_on_selected_open_shift(): void
    {
        [$owner, $organization, $branch, $supplier, $product] = $this->fixture('Cash Payment Market', '0.000');
        $invoice = $this->receiveInvoice($owner, $supplier, $product, '1.000', '25.00');
        $shift = CashShift::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'cashier_id' => $owner->getKey(), 'opened_by' => $owner->getKey(), 'status' => 'open',
            'opening_amount' => '100.00', 'expected_cash' => '100.00', 'opened_at' => now(),
        ]);

        $this->actingAs($owner)->post(route('supplier-payments.store'), [
            'purchase_invoice_id' => $invoice->getKey(), 'client_operation_uuid' => (string) Str::uuid(),
            'amount' => '7.00', 'payment_method' => 'cash', 'cash_shift_id' => $shift->getKey(), 'notes' => 'شراء نقدي',
        ])->assertRedirect(route('supplier-payments.index'));

        $payment = SupplierPayment::query()->firstOrFail();
        $this->assertDatabaseHas('cash_movements', [
            'supplier_payment_id' => $payment->getKey(), 'cash_shift_id' => $shift->getKey(), 'movement_type' => 'cash_out', 'amount' => '7.00',
        ]);
        $this->assertSame('93.00', $shift->fresh()->expectedCashAmount());
    }

    public function test_supplier_payment_cannot_pay_invoice_from_another_organization(): void
    {
        [$owner, $organization, $branch, $supplier, $product] = $this->fixture('Local Pay Market', '0.000');
        [$otherOwner, $otherOrganization, $otherBranch, $otherSupplier, $otherProduct] = $this->fixture('Other Pay Market', '0.000');
        $otherInvoice = $this->receiveInvoice($otherOwner, $otherSupplier, $otherProduct, '1.000', '10.00');

        $this->actingAs($owner)->from(route('supplier-payments.index'))->post(route('supplier-payments.store'), [
            'purchase_invoice_id' => $otherInvoice->getKey(), 'client_operation_uuid' => (string) Str::uuid(),
            'amount' => '2.00', 'payment_method' => 'bank_transfer', 'reference_number' => 'TRX-OTHER',
        ])->assertRedirect(route('supplier-payments.index'))->assertSessionHasErrors('purchase_invoice_id');

        $this->assertDatabaseCount('supplier_payments', 0);
    }

    private function fixture(string $name, string $initialQuantity): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'trial', 'timezone' => 'Africa/Cairo', 'currency' => 'EGP',
        ]);
        $branch = $organization->branches()->create(['name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'status' => 'active']);
        $organization->users()->attach($user->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($user->getKey());
        $supplier = Supplier::query()->create(['organization_id' => $organization->getKey(), 'name' => 'مورد تجريبي', 'is_active' => true]);
        $unitId = (int) Unit::query()->where('code', 'piece')->value('id');
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(), 'base_unit_id' => $unitId, 'created_by' => $user->getKey(),
            'sku' => 'SKU-'.Str::upper(Str::random(8)), 'name' => 'منتج تجريبي', 'purchase_price' => '10.00', 'selling_price' => '15.00',
            'low_stock_threshold' => '1.000', 'is_active' => true,
        ]);
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => $initialQuantity,
        ]);

        return [$user, $organization, $branch, $supplier, $product];
    }

    private function receiveInvoice(User $owner, Supplier $supplier, Product $product, string $quantity, string $unitCost): PurchaseInvoice
    {
        $this->actingAs($owner)->post(route('purchase-invoices.store'), [
            'supplier_id' => $supplier->getKey(), 'paid_amount' => '0.00',
            'items' => [['product_id' => $product->getKey(), 'quantity' => $quantity, 'unit_cost' => $unitCost]],
        ])->assertRedirect(route('purchase-invoices.index'));

        return PurchaseInvoice::query()->where('supplier_id', $supplier->getKey())->latest('id')->firstOrFail();
    }
}
