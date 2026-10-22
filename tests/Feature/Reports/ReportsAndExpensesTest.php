<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Expense;
use App\Models\InventoryBalance;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportsAndExpensesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_record_expense_and_reports_calculate_operating_profit(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Reporting Market');
        $product = $this->createProduct($organization, $owner, $branch);
        $shift = CashShift::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'cashier_id' => $owner->getKey(), 'opened_by' => $owner->getKey(), 'status' => 'open',
            'opening_amount' => '100.00', 'opened_at' => now(),
        ]);
        $sale = Sale::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'cash_shift_id' => $shift->getKey(), 'cashier_id' => $owner->getKey(),
            'invoice_number' => 'TEST-REPORT-1', 'client_operation_uuid' => (string) Str::uuid(),
            'status' => 'paid', 'subtotal' => '100.00', 'discount_total' => '0.00',
            'tax_total' => '0.00', 'total' => '100.00', 'sold_at' => now(),
        ]);
        SaleItem::query()->create([
            'organization_id' => $organization->getKey(), 'sale_id' => $sale->getKey(),
            'product_id' => $product->getKey(), 'product_sku_snapshot' => $product->sku,
            'product_name_snapshot' => $product->name, 'unit_name_snapshot' => 'قطعة',
            'quantity' => '2.000', 'unit_price' => '50.00', 'unit_cost_snapshot' => '30.00',
            'discount_amount' => '0.00', 'tax_amount' => '0.00', 'line_total' => '100.00',
        ]);

        $today = now($organization->timezone)->toDateString();
        $this->actingAs($owner)->post(route('expenses.store'), [
            'category' => 'كهرباء', 'description' => 'فاتورة الكهرباء', 'amount' => '50.00',
            'payment_method' => 'bank_transfer', 'expense_date' => $today,
        ])->assertRedirect(route('expenses.index'));

        $this->assertDatabaseHas('expenses', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'category' => 'كهرباء', 'amount' => '50.00',
        ]);

        $this->actingAs($owner)->get(route('reports.index', [
            'from_date' => $today, 'to_date' => $today, 'branch_id' => (string) $branch->getKey(),
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->where('summary.invoiceCount', 1)
            ->where('summary.sales', '100.00')
            ->where('summary.costOfGoods', '60.00')
            ->where('summary.grossProfit', '40.00')
            ->where('summary.expenses', '50.00')
            ->where('summary.netProfit', '-10.00'));
    }

    public function test_expense_date_validation_uses_organization_local_today(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Timezone Expense Market');
        $organization->update(['timezone' => 'Africa/Cairo']);

        $previousAppTimezone = (string) config('app.timezone');
        $previousPhpTimezone = date_default_timezone_get();
        $this->app['config']->set('app.timezone', 'UTC');
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::parse('2026-01-01 22:30:00', 'UTC'));

        try {
            // In UTC it is still Jan 1, but the organization's local date is already Jan 2.
            $localToday = now('Africa/Cairo')->toDateString();
            $this->assertSame('2026-01-02', $localToday);

            $this->actingAs($owner)->post(route('expenses.store'), [
                'category' => 'تشغيل',
                'description' => 'مصروف بتاريخ اليوم المحلي',
                'amount' => '10.00',
                'payment_method' => 'bank_transfer',
                'expense_date' => $localToday,
            ])->assertRedirect(route('expenses.index'));

            $this->assertDatabaseHas('expenses', [
                'organization_id' => $organization->getKey(),
                'branch_id' => $branch->getKey(),
                'expense_date' => $localToday,
                'amount' => '10.00',
            ]);
        } finally {
            Carbon::setTestNow();
            $this->app['config']->set('app.timezone', $previousAppTimezone);
            date_default_timezone_set($previousPhpTimezone);
        }
    }

    public function test_cash_expense_requires_open_shift_and_is_recorded_in_cash_ledger(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Cash Expense Market');
        $payload = [
            'category' => 'نقل', 'description' => 'مصاريف توصيل', 'amount' => '25.00',
            'payment_method' => 'cash', 'expense_date' => now($organization->timezone)->toDateString(),
        ];

        $this->actingAs($owner)->from(route('expenses.index'))
            ->post(route('expenses.store'), $payload)
            ->assertRedirect(route('expenses.index'))
            ->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('expenses', 0);

        $this->actingAs($owner)->post(route('pos.shifts.open'), ['opening_amount' => '100.00'])
            ->assertRedirect(route('pos.index'));
        $this->actingAs($owner)->post(route('expenses.store'), $payload)
            ->assertRedirect(route('expenses.index'));

        $this->assertDatabaseHas('cash_movements', [
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'movement_type' => 'cash_out', 'amount' => '25.00',
        ]);
        $this->assertDatabaseCount('expenses', 1);
    }

    public function test_cashier_cannot_view_reports_or_create_expenses(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Permissions Report Market');
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($cashier)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($cashier)->post(route('expenses.store'), [
            'category' => 'تشغيل', 'description' => 'غير مصرح', 'amount' => '10.00',
            'payment_method' => 'bank_transfer', 'expense_date' => now($organization->timezone)->toDateString(),
        ])->assertForbidden();

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_cashier_cannot_access_reports_even_if_they_supply_another_branch(): void
    {
        [$owner, $organization, $branch] = $this->createOrganizationFor('Scoped Reports Market');
        $secondBranch = $organization->branches()->create([
            'name' => 'فرع آخر', 'code' => 'BR-02', 'is_main' => false, 'status' => 'active',
        ]);
        $cashier = User::factory()->create();
        $organization->users()->attach($cashier->getKey(), ['role' => 'cashier', 'is_default' => true]);
        $branch->users()->attach($cashier->getKey());

        $this->actingAs($owner)->get(route('reports.index', [
            'from_date' => now($organization->timezone)->toDateString(), 'to_date' => now($organization->timezone)->toDateString(), 'branch_id' => (string) $branch->getKey(),
        ]))->assertOk();

        // Cashier is denied reports before branch selection is processed.
        $this->actingAs($cashier)->get(route('reports.index', [
            'from_date' => now($organization->timezone)->toDateString(), 'to_date' => now($organization->timezone)->toDateString(), 'branch_id' => (string) $secondBranch->getKey(),
        ]))->assertForbidden();
    }

    private function createOrganizationFor(string $name): array
    {
        $owner = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'trial', 'timezone' => 'Africa/Cairo', 'currency' => 'EGP',
        ]);
        $branch = $organization->branches()->create([
            'name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'status' => 'active',
        ]);
        $organization->users()->attach($owner->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($owner->getKey());

        return [$owner, $organization, $branch];
    }

    private function createProduct(Organization $organization, User $owner, Branch $branch): Product
    {
        $unit = Unit::query()->where('code', 'piece')->firstOrFail();
        $product = Product::query()->create([
            'organization_id' => $organization->getKey(), 'base_unit_id' => $unit->getKey(),
            'created_by' => $owner->getKey(), 'sku' => 'REPORT-001', 'name' => 'منتج تقرير',
            'purchase_price' => '30.00', 'selling_price' => '50.00',
            'low_stock_threshold' => '1.000', 'is_active' => true,
        ]);
        InventoryBalance::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'product_id' => $product->getKey(), 'quantity' => '10.000',
        ]);

        return $product;
    }
}
