<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerReceivablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_receivables_report_requires_authentication(): void
    {
        $this->get('/customers/receivables')->assertRedirect('/login');
        $this->get('/customers/receivables/export')->assertRedirect('/login');
    }

    public function test_owner_can_view_opening_balance_as_unaged_receivable(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Receivables Test Market', 'slug' => 'receivables-'.Str::lower(Str::random(8)),
            'status' => 'trial', 'timezone' => 'Africa/Cairo', 'currency' => 'EGP',
        ]);
        $branch = $organization->branches()->create([
            'name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'status' => 'active',
        ]);
        $organization->users()->attach($owner->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($owner->getKey());
        Customer::query()->create([
            'organization_id' => $organization->getKey(), 'code' => 'CUS-AR-01',
            'name' => 'عميل رصيد افتتاحي', 'balance' => '120.50', 'credit_limit' => '500.00',
        ]);

        $this->actingAs($owner)->get(route('customers.receivables.index'))
            ->assertOk()->assertInertia(fn ($page) => $page
                ->component('Customers/Receivables')
                ->where('summary.customerCount', 1)
                ->where('summary.totalReceivable', '120.50')
                ->where('summary.unagedBalance', '120.50')
                ->where('customers.data.0.name', 'عميل رصيد افتتاحي')
                ->where('customers.data.0.unagedBalance', '120.50'));
    }

    public function test_report_does_not_include_another_organizations_receivable(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Own Receivables Market', 'slug' => 'own-recv-'.Str::lower(Str::random(8)),
            'status' => 'trial', 'timezone' => 'Africa/Cairo', 'currency' => 'EGP',
        ]);
        $branch = $organization->branches()->create([
            'name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'status' => 'active',
        ]);
        $organization->users()->attach($owner->getKey(), ['role' => 'owner', 'is_default' => true]);
        $branch->users()->attach($owner->getKey());

        $otherOrganization = Organization::query()->create([
            'name' => 'Other Receivables Market', 'slug' => 'other-recv-'.Str::lower(Str::random(8)),
            'status' => 'trial', 'timezone' => 'Africa/Cairo', 'currency' => 'EGP',
        ]);
        Customer::query()->create([
            'organization_id' => $otherOrganization->getKey(), 'name' => 'عميل خارج المؤسسة',
            'balance' => '999.00', 'credit_limit' => '1000.00',
        ]);

        $this->actingAs($owner)->get(route('customers.receivables.index'))
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('summary.customerCount', 0)
                ->where('summary.totalReceivable', '0.00')
                ->where('customers.data', []));
    }
}
