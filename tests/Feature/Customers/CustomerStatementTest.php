<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerAccountTransaction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerStatementTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_statement_calculates_opening_running_and_closing_balances_and_exports_csv(): void
    {
        [$owner, $organization, $branch, $customer] = $this->fixture('Customer Statement Market');
        $this->transaction($organization, $branch, $owner, $customer, 'opening_balance', '100.00', '0.00', now()->subDays(45));
        $this->transaction($organization, $branch, $owner, $customer, 'payment', '0.00', '60.00', now()->subDays(44));
        $this->transaction($organization, $branch, $owner, $customer, 'sale', '20.00', '0.00', now()->subDays(2));
        $this->transaction($organization, $branch, $owner, $customer, 'payment', '0.00', '5.00', now()->subDay());

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $url = route('customers.statement', ['customerId' => $customer->getKey(), 'from' => $from, 'to' => $to]);
        $this->actingAs($owner)->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->component('Customers/Statement')
            ->where('summary.openingBalance', '40.00')
            ->where('summary.debitTotal', '20.00')
            ->where('summary.creditTotal', '5.00')
            ->where('summary.closingBalance', '55.00')
            ->where('summary.currentBalance', '55.00')
            ->where('summary.transactionCount', 2)
            ->has('statement.data', 2)
            ->where('statement.data.0.balanceAfter', '60.00')
            ->where('statement.data.1.balanceAfter', '55.00'));

        $export = $this->actingAs($owner)->get(route('customers.statement.export', [
            'customerId' => $customer->getKey(), 'from' => $from, 'to' => $to,
        ]));
        $export->assertOk();
        $csv = $export->streamedContent();
        $this->assertStringContainsString('Date', $csv);
        $this->assertStringContainsString('20.00', $csv);
        $this->assertStringContainsString('55.00', $csv);
    }

    public function test_customer_statement_cannot_view_another_organizations_customer(): void
    {
        [$owner] = $this->fixture('Customer Statement Local Market');
        [, $foreignOrganization] = $this->fixture('Customer Statement Foreign Market');
        $foreignCustomer = Customer::query()->create([
            'organization_id' => $foreignOrganization->getKey(), 'name' => 'عميل خارجي', 'balance' => '0.00',
        ]);

        $this->actingAs($owner)->get(route('customers.statement', $foreignCustomer->getKey()))->assertNotFound();
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
        $customer = Customer::query()->create([
            'organization_id' => $organization->getKey(), 'name' => 'عميل كشف حساب', 'balance' => '0.00',
        ]);

        return [$owner, $organization, $branch, $customer];
    }

    private function transaction(Organization $organization, $branch, User $owner, Customer $customer, string $type, string $debit, string $credit, $createdAt): void
    {
        $transaction = CustomerAccountTransaction::query()->create([
            'organization_id' => $organization->getKey(), 'branch_id' => $branch->getKey(),
            'customer_id' => $customer->getKey(), 'created_by' => $owner->getKey(),
            'operation_uuid' => (string) Str::uuid(), 'transaction_type' => $type,
            'debit_amount' => $debit, 'credit_amount' => $credit,
            'balance_after' => '0.00', 'description' => 'اختبار كشف حساب',
        ]);
        $transaction->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
    }
}
