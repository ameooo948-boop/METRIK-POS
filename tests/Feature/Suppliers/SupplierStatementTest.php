<?php

namespace Tests\Feature\Suppliers;

use App\Models\Organization;
use App\Models\Supplier;
use App\Models\SupplierTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierStatementTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_statement_calculates_payable_balance_and_exports_csv(): void
    {
        [$owner, $organization, $branch, $supplier] = $this->fixture('Supplier Statement Market');
        $this->transaction($organization, $branch, $owner, $supplier, 'purchase_invoice', '0.00', '100.00', now()->subDays(45));
        $this->transaction($organization, $branch, $owner, $supplier, 'payment', '60.00', '0.00', now()->subDays(44));
        $this->transaction($organization, $branch, $owner, $supplier, 'purchase_invoice', '0.00', '20.00', now()->subDays(2));
        $this->transaction($organization, $branch, $owner, $supplier, 'purchase_return', '5.00', '0.00', now()->subDay());

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $url = route('suppliers.statement', ['supplierId' => $supplier->getKey(), 'from' => $from, 'to' => $to]);
        $this->actingAs($owner)->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->component('Suppliers/Statement')
            ->where('summary.openingBalance', '40.00')
            ->where('summary.debitTotal', '5.00')
            ->where('summary.creditTotal', '20.00')
            ->where('summary.closingBalance', '55.00')
            ->where('summary.currentBalance', '55.00')
            ->where('summary.transactionCount', 2)
            ->has('statement.data', 2)
            ->where('statement.data.0.balanceAfter', '60.00')
            ->where('statement.data.1.balanceAfter', '55.00'));

        $export = $this->actingAs($owner)->get(route('suppliers.statement.export', [
            'supplierId' => $supplier->getKey(), 'from' => $from, 'to' => $to,
        ]));
        $export->assertOk();
        $csv = $export->streamedContent();
        $this->assertStringContainsString('Date', $csv);
        $this->assertStringContainsString('20.00', $csv);
        $this->assertStringContainsString('55.00', $csv);
    }

    public function test_supplier_statement_cannot_view_another_organizations_supplier(): void
    {
        [$owner] = $this->fixture('Supplier Statement Local Market');
        [, $foreignOrganization] = $this->fixture('Supplier Statement Foreign Market');
        $foreignSupplier = Supplier::query()->create([
            'organization_id' => $foreignOrganization->getKey(), 'name' => 'مورد خارجي', 'is_active' => true,
        ]);

        $this->actingAs($owner)->get(route('suppliers.statement', $foreignSupplier->getKey()))->assertNotFound();
    }

    private function fixture(string $name): array
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
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->getKey(), 'name' => 'مورد كشف حساب', 'is_active' => true,
        ]);

        return [$owner, $organization, $branch, $supplier];
    }

    private function transaction(Organization $organization, $branch, User $owner, Supplier $supplier, string $type, string $debit, string $credit, $createdAt): void
    {
        $transaction = SupplierTransaction::query()->create([
            'organization_id' => $organization->getKey(), 'supplier_id' => $supplier->getKey(),
            'branch_id' => $branch->getKey(), 'created_by' => $owner->getKey(),
            'transaction_type' => $type, 'debit' => $debit, 'credit' => $credit,
            'balance_after' => '0.00', 'notes' => 'اختبار كشف حساب المورد',
        ]);
        $transaction->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
    }
}
