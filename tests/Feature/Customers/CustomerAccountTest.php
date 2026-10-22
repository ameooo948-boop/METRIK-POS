<?php

namespace Tests\Feature\Customers;

use App\Models\CashShift;
use App\Models\Customer;
use App\Models\CustomerAccountTransaction;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Unit;
use App\Models\User;
use App\Services\Reports\SalesReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_customer_with_opening_balance_and_view_account(): void
    {
        [$owner, $organization] = $this->createOrganizationFor('Customer Accounts Market');

        $this->actingAs($owner)->post(route('customers.store'), [
            'name' => '  أحمد علي ', 'code' => 'cus-001', 'phone' => '01000000000',
            'credit_limit' => '500.00', 'opening_balance' => '75.50',
        ])->assertRedirect();

        $customer = Customer::query()->where('organization_id', $organization->getKey())->firstOrFail();
        $this->assertSame('CUS-001', $customer->code);
        $this->assertSame('75.50', $customer->balance);
        $this->assertDatabaseHas('customer_account_transactions', [
            'customer_id' => $customer->getKey(), 'transaction_type' => 'opening_balance',
            'debit_amount' => '75.50', 'credit_amount' => '0.00', 'balance_after' => '75.50',
        ]);

        $this->actingAs($owner)->get(route('customers.show', $customer->getKey()))
            ->assertOk()->assertInertia(fn ($page) => $page
                ->component('Customers/Show')->where('customer.balance', '75.50'));
    }

    public function test_customer_codes_are_unique_in_an_organization_and_cross_tenant_data_is_hidden(): void
    {
        [$owner, $organization] = $this->createOrganizationFor('Customer Tenant Market');
        [$otherOwner, $otherOrganization] = $this->createOrganizationFor('Other Customer Market');
        Customer::query()->create(['organization_id' => $otherOrganization->getKey(), 'code' => 'LOCAL-01', 'name' => 'عميل خارجي']);

        $this->actingAs($owner)->post(route('customers.store'), [
            'name' => 'عميل محلي', 'code' => 'local-01', 'credit_limit' => '0.00',
        ])->assertRedirect();
        $this->actingAs($owner)->from(route('customers.index'))->post(route('customers.store'), [
            'name' => 'كود مكرر', 'code' => 'LOCAL-01', 'credit_limit' => '0.00',
        ])->assertSessionHasErrors('code');
        $this->actingAs($owner)->get(route('customers.show', Customer::query()->where('organization_id', $otherOrganization->getKey())->firstOrFail()->getKey()))
            ->assertNotFound();
    }

    public function test_cashier_cannot_access_customer_account_management(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Customer Permissions Market');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($cashier)->get(route('customers.index'))->assertForbidden();
    }

    public function test_paid_sale_can_be_linked_to_a_customer(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Customer POS Link Market');
        $customer = Customer::query()->create(['organization_id' => $organization->getKey(), 'name' => 'عميل نقدي']);
        $product = $this->createProduct($organization, $owner, $branch, 'CUS-POS-001', 'مياه', '3.000');
        $this->openShift($owner);
        $uuid = (string) Str::uuid();

        $this->actingAs($owner)->post(route('pos.checkout'), [
            'client_operation_uuid' => $uuid, 'payment_method' => 'cash', 'customer_id' => $customer->getKey(),
            'items' => [['product_id' => $product->getKey(), 'quantity' => '1.000']],
        ])->assertRedirect();
        $this->assertDatabaseHas('sales', ['client_operation_uuid' => $uuid, 'customer_id' => $customer->getKey(), 'status' => 'paid']);
        $this->assertSame('0.00', $customer->refresh()->balance);
    }

    public function test_credit_sale_debits_customer_account_and_checks_credit_limit(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Customer Credit Market');
        $customer = Customer::query()->create(['organization_id' => $organization->getKey(), 'name' => 'عميل آجل', 'credit_limit' => '20.00']);
        $product = $this->createProduct($organization, $owner, $branch, 'CUS-CREDIT-001', 'عصير', '5.000');
        $this->openShift($owner);
        $uuid = (string) Str::uuid();

        $this->actingAs($owner)->post(route('pos.checkout'), [
            'client_operation_uuid' => $uuid, 'payment_method' => 'credit', 'customer_id' => $customer->getKey(),
            'items' => [['product_id' => $product->getKey(), 'quantity' => '2.000']],
        ])->assertRedirect();
        $sale = Sale::query()->where('client_operation_uuid', $uuid)->firstOrFail();
        $this->assertSame('due', $sale->status);
        $this->assertSame('16.00', $customer->refresh()->balance);
        $this->assertDatabaseHas('customer_account_transactions', ['customer_id' => $customer->getKey(), 'sale_id' => $sale->getKey(), 'transaction_type' => 'sale', 'debit_amount' => '16.00']);

        $report = app(SalesReport::class)->handle(
            (int) $organization->getKey(), [(int) $branch->getKey()], now($organization->timezone)->toDateString(), now($organization->timezone)->toDateString(),
        );
        $this->assertSame('16.00', $report['summary']['sales']);
        $this->assertSame('10.00', $report['summary']['costOfGoods']);
        $this->assertSame('6.00', $report['summary']['grossProfit']);

        $product2 = $this->createProduct($organization, $owner, $branch, 'CUS-CREDIT-002', 'شاي', '5.000');
        $this->actingAs($owner)->from(route('pos.index'))->post(route('pos.checkout'), [
            'client_operation_uuid' => (string) Str::uuid(), 'payment_method' => 'credit', 'customer_id' => $customer->getKey(),
            'items' => [['product_id' => $product2->getKey(), 'quantity' => '1.000']],
        ])->assertRedirect(route('pos.index'))->assertSessionHasErrors('customer_id');
        $this->assertSame('5.000', (string) InventoryBalance::query()->where('branch_id', $branch->getKey())->where('product_id', $product2->getKey())->firstOrFail()->quantity);
    }

    public function test_partial_customer_payment_is_idempotent_and_closes_invoice_when_fully_paid(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Customer Collection Market');
        $customer = Customer::query()->create(['organization_id' => $organization->getKey(), 'name' => 'عميل تحصيل', 'credit_limit' => '100.00']);
        $product = $this->createProduct($organization, $owner, $branch, 'CUS-COLLECT-001', 'أرز', '5.000');
        $this->openShift($owner);
        $saleUuid = (string) Str::uuid();
        $this->actingAs($owner)->post(route('pos.checkout'), [
            'client_operation_uuid' => $saleUuid, 'payment_method' => 'credit', 'customer_id' => $customer->getKey(),
            'items' => [['product_id' => $product->getKey(), 'quantity' => '2.000']],
        ])->assertRedirect();
        $sale = Sale::query()->where('client_operation_uuid', $saleUuid)->firstOrFail();
        $collectUuid = (string) Str::uuid();
        $payload = ['sale_id' => $sale->getKey(), 'operation_uuid' => $collectUuid, 'amount' => '5.00', 'payment_method' => 'cash'];
        $this->actingAs($owner)->post(route('customers.payments.store', $customer->getKey()), $payload)->assertRedirect();
        $this->actingAs($owner)->post(route('customers.payments.store', $customer->getKey()), $payload)->assertRedirect();
        $this->assertSame('11.00', $customer->refresh()->balance);
        $this->assertSame('partially_paid', $sale->refresh()->status);
        $this->assertSame(1, CustomerAccountTransaction::query()->where('operation_uuid', $collectUuid)->count());

        $this->actingAs($owner)->post(route('customers.payments.store', $customer->getKey()), [
            'sale_id' => $sale->getKey(), 'operation_uuid' => (string) Str::uuid(), 'amount' => '11.00', 'payment_method' => 'cash',
        ])->assertRedirect();
        $this->assertSame('0.00', $customer->refresh()->balance);
        $this->assertSame('paid', $sale->refresh()->status);
        $this->assertDatabaseHas('cash_movements', ['sale_id' => $sale->getKey(), 'movement_type' => 'cash_in', 'amount' => '5.00']);
    }

    public function test_offline_sync_rejects_credit_payment_method(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Customer Offline Credit Market');
        $customer = Customer::query()->create(['organization_id' => $organization->getKey(), 'name' => 'عميل', 'credit_limit' => '100.00']);
        $product = $this->createProduct($organization, $owner, $branch, 'CUS-OFFLINE-001', 'لبن', '3.000');
        $this->openShift($owner);
        $shift = CashShift::query()->where('cashier_id', $owner->getKey())->where('status', 'open')->firstOrFail();
        $this->actingAs($owner)->postJson(route('pos.offline.sync'), [
            'client_operation_uuid' => (string) Str::uuid(), 'payment_method' => 'credit', 'customer_id' => $customer->getKey(),
            'shift_id' => $shift->getKey(), 'offline_created_at' => now()->toIso8601String(),
            'items' => [['product_id' => $product->getKey(), 'quantity' => '1.000', 'price_snapshot' => $product->selling_price]],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    }

    private function openShift(User $user): void
    {
        $this->actingAs($user)->post(route('pos.shifts.open'), ['opening_amount' => '0.00'])->assertRedirect(route('pos.index'));
    }

    private function createOrganizationFor(string $name): array
    {
        $owner = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'trial', 'timezone' => 'Africa/Cairo', 'currency' => 'EGP',
        ]);
        $branch = $organization->branches()->create(['name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'status' => 'active']);
        $organization->users()->attach($owner->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($owner->getKey());
        return [$owner, $organization, $branch];
    }

    private function createProduct(Organization $organization, User $owner, $branch, string $sku, string $name, string $stock): Product
    {
        $unit = Unit::query()->where('code', 'piece')->firstOrFail();
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(), 'base_unit_id' => $unit->getKey(), 'created_by' => $owner->getKey(),
            'sku' => $sku, 'name' => $name, 'purchase_price' => '5.00', 'selling_price' => '8.00',
            'low_stock_threshold' => '1.000', 'is_active' => true,
        ]);
        InventoryBalance::query()->create(['organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(), 'quantity' => $stock]);
        InventoryMovement::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(), 'product_id' => $product->getKey(),
            'created_by' => $owner->getKey(), 'movement_type' => 'opening', 'quantity_delta' => $stock, 'balance_after' => $stock,
            'source' => 'test', 'reason' => 'رصيد افتتاحي للاختبار',
        ]);
        return $product;
    }
}
